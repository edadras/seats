<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Sites\SiteProvisioner;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\EmailVerification;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\Locale\Locales;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * Somebody sets up their own account.
 *
 * One request creates the four things that have to exist before a venue can do anything: a person,
 * an organiser to belong to, a subscription, and a website. Doing it in four requests would mean
 * four ways to end up half signed up, and the half with no subscription is the one that produces a
 * support ticket at eight in the morning.
 *
 * The email address is verified afterwards, not before. Making somebody wait for a code before
 * they can look at anything is how a trial becomes a bounce; instead an unverified account can
 * build everything and cannot put a site on the public internet (`sites.publish`). That is the one
 * thing an unverified address could be used to abuse.
 */
class SignupController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly SiteProvisioner $sites,
    ) {}

    /** What somebody choosing a plan is choosing between. */
    public function plans()
    {
        return response()->json([
            'data' => Plan::where('is_active', true)->orderBy('price_amount')->get()
                ->map(fn (Plan $plan) => [
                    'key' => $plan->key,
                    'name' => $plan->name,
                    'price_amount' => $plan->price_amount,
                    'currency' => $plan->currency,
                    'interval' => $plan->interval,
                    'limits' => $plan->limits ?? [],
                ])->values(),
        ]);
    }

    public function register(Request $request)
    {
        $this->throttle('signup:ip:'.$request->ip(), 10, 3600);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'organisation' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            // Twelve rather than eight: this password protects a box office, and length is the
            // only rule that reliably helps.
            'password' => ['required', 'string', 'min:12', 'max:200'],
            'locale' => ['sometimes', 'string', 'max:12'],
            'timezone' => ['sometimes', 'string', 'timezone'],
            'plan' => ['sometimes', 'string', 'max:40'],
        ]);

        $email = mb_strtolower(trim($data['email']));

        // Said plainly rather than hidden. This endpoint creates accounts; somebody who already
        // has one needs to be told to sign in, not left guessing why nothing happened.
        if ($this->tenants->runUnscoped(fn () => User::where('email', $email)->exists())) {
            throw ApiException::conflict('email_taken', 'That email address already has an account. Sign in instead.');
        }

        $plan = $this->planFor($data['plan'] ?? null);
        $locale = Locales::normalise($data['locale'] ?? app()->getLocale());
        $timezone = $data['timezone'] ?? 'UTC';

        [$user, $tenant, $site] = DB::transaction(function () use ($data, $email, $plan, $locale, $timezone) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $email,
                'password' => Hash::make($data['password']),
                'locale' => $locale,
            ]);

            $tenant = Tenant::create([
                'name' => $data['organisation'],
                'slug' => $this->uniqueSlug($data['organisation']),
                'status' => 'active',
                'timezone' => $timezone,
                'locale' => $locale,
            ]);

            return $this->tenants->runAs($tenant, function () use ($user, $tenant, $plan, $locale, $timezone, $data) {
                TenantUser::create([
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                    'role' => 'owner',
                ]);

                Subscription::create([
                    'tenant_id' => $tenant->id,
                    'plan_id' => $plan->id,
                    'status' => 'active',
                    'current_period_start' => now(),
                    'current_period_end' => now()->addMonth(),
                ]);

                // A site from the first minute, in draft. An organiser with nothing to look at has
                // nothing to decide about, and provisioning it later is a second thing to go wrong.
                $site = $this->sites->create($data['organisation'], [
                    'locale' => $locale,
                    'timezone' => $timezone,
                ]);

                return [$user, $tenant, $site];
            });
        });

        $this->sendCode($user);

        $token = $user->createToken('signup');

        return response()->json([
            'token' => $token->plainTextToken,
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'status' => $tenant->status,
                'timezone' => $tenant->timezone,
                'locale' => $tenant->locale,
            ],
            'role' => 'owner',
            'site_id' => $site->id,
            'email_verified' => false,
            'plan' => $plan->key,
        ], 201);
    }

    /** Type the code from the email. */
    public function verify(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string', 'max:12'],
        ]);

        $this->throttle('verify:ip:'.$request->ip(), 20, 900);

        $user = $this->tenants->runUnscoped(
            fn () => User::where('email', mb_strtolower($data['email']))->first()
        );

        $record = $user
            ? EmailVerification::where('user_id', $user->id)->latest('created_at')->first()
            : null;

        if (! $user || ! $record || ! $record->isUsable()) {
            throw ApiException::unprocessable('code_expired', 'That code has expired. Ask for a new one.');
        }

        // Counted before it is checked, so a wrong guess costs an attempt whatever happens next.
        $record->increment('attempts');

        if (! $record->matches($data['code'])) {
            throw ApiException::unprocessable('code_wrong', 'That code is not right.');
        }

        $user->forceFill(['email_verified_at' => now()])->save();
        $record->delete();

        return response()->json(['email_verified' => true]);
    }

    public function resend(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        // Hard: a resend endpoint is a way to send email from somebody else's server.
        $this->throttle('resend:'.mb_strtolower($data['email']), 5, 3600);
        $this->throttle('resend:ip:'.$request->ip(), 10, 3600);

        $user = $this->tenants->runUnscoped(
            fn () => User::where('email', mb_strtolower($data['email']))->first()
        );

        if ($user && ! $user->email_verified_at) {
            $this->sendCode($user);
        }

        // The same answer either way: this endpoint must not say who has an account.
        return response()->json(['sent' => true]);
    }

    /* --------------------------------------------------------------------------- helpers */

    private function sendCode(User $user): void
    {
        [, $code] = EmailVerification::issueFor($user);

        try {
            Mail::raw(
                __('signup.email.body', ['code' => $code, 'name' => $user->name], $user->locale),
                fn ($message) => $message->to($user->email)
                    ->subject(__('signup.email.subject', [], $user->locale))
            );
        } catch (Throwable $e) {
            // The account exists and the code exists; a mail server being down is a reason to
            // resend, not a reason to fail a signup that has already created four rows.
            Log::warning('Verification email could not be sent', [
                'user' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function planFor(?string $key): Plan
    {
        $plan = $key ? Plan::where('key', $key)->where('is_active', true)->first() : null;

        return $plan ?? Plan::where('is_active', true)->orderBy('price_amount')->firstOr(
            fn () => throw ApiException::conflict('no_plans', 'Signups are closed at the moment.')
        );
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug(mb_substr($name, 0, 40)) ?: 'venue';
        $slug = $base;
        $suffix = 2;

        while ($this->tenants->runUnscoped(fn () => Tenant::where('slug', $slug)->exists())) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    private function throttle(string $key, int $limit, int $seconds): void
    {
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            throw new ApiException('too_many_attempts', sprintf(
                'Too many attempts. Try again in %d seconds.', RateLimiter::availableIn($key)
            ), 429);
        }

        RateLimiter::hit($key, $seconds);
    }
}
