<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Auth\TwoFactor;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\Access\Gate;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly Gate $gate,
    ) {}

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:100'],
        ]);

        // Throttled per email *and* per IP: neither an attacker spraying one account nor one
        // spraying many accounts from a single host gets unlimited attempts.
        $throttleKeys = [
            'login:email:'.strtolower($data['email']),
            'login:ip:'.$request->ip(),
        ];

        foreach ($throttleKeys as $key) {
            if (RateLimiter::tooManyAttempts($key, 10)) {
                throw new ApiException(
                    'too_many_attempts',
                    'Too many login attempts. Try again shortly.',
                    429,
                    [],
                    null,
                    ['seconds' => RateLimiter::availableIn($key)],
                );
            }
        }

        $user = $this->tenantContext->runUnscoped(fn () => User::where('email', $data['email'])->first());

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            foreach ($throttleKeys as $key) {
                RateLimiter::hit($key, 900);
            }

            // One message for both cases, so the endpoint cannot be used to enumerate accounts.
            throw ApiException::unauthorized('invalid_credentials', 'These credentials do not match our records.');
        }

        foreach ($throttleKeys as $key) {
            RateLimiter::clear($key);
        }

        $membership = $user->memberships()->first();

        if (! $membership) {
            throw ApiException::forbidden('This account is not a member of any organiser.', 'no_membership');
        }

        $tenant = $this->tenantContext->runUnscoped(fn () => Tenant::find($membership->tenant_id));

        /*
         * This account signs in somewhere else.
         *
         * Checked after the password rather than before it, deliberately: answering "that account
         * uses single sign-on" to anybody who types an address would turn this endpoint into a way
         * of asking which organisations use which provider. Somebody who got the password right
         * has already proved they belong to the conversation.
         *
         * It applies to everybody, the owner included. A password that still worked for one person
         * would be the password an attacker goes looking for, and it is the one this account has
         * decided should not exist.
         */
        if ($tenant && app(\App\Domain\Auth\SingleSignOn::class)->forTenant($tenant)?->locksOutPasswords()) {
            throw ApiException::denied(
                'sso_required',
                'This account signs in through its own organisation. Use the link your venue gave you.'
            );
        }

        /*
         * The second step, when there is one.
         *
         * The password was right, so the answer is not a refusal — it is a half-finished sign-in
         * with a challenge that is worth nothing on its own. The challenge is a random handle in
         * the cache rather than anything derived from the account, so holding one proves only that
         * somebody just typed this account's password.
         */
        if ($user->hasTwoFactor()) {
            $challenge = Str::random(48);

            Cache::put('2fa:'.$challenge, [
                'user_id' => $user->id,
                'device' => $data['device_name'] ?? 'api',
            ], now()->addMinutes(5));

            return response()->json([
                'two_factor_required' => true,
                'challenge' => $challenge,
            ], 200);
        }

        /*
         * The account requires it and this person has not set it up.
         *
         * Signing them in anyway would make the requirement a suggestion; refusing outright would
         * leave them with no way to comply. So they get in, and the panel is told to make them
         * finish before they can do anything else.
         */
        $mustEnrol = (bool) ($tenant?->require_two_factor);

        $token = $user->createToken($data['device_name'] ?? 'api');

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
            'role' => $membership->role,
            'permissions' => $this->permissionsFor($membership, $tenant),
            'agent' => $this->tenantContext->runAs($tenant, fn () => $this->agencyOf($user)),
            'programme_manager' => $this->tenantContext->runAs(
                $tenant,
                fn () => app(\App\Domain\Programme\EventManagers::class)->isOne($user),
            ),
            // So the panel can put the verification bar back for somebody who signed up, closed
            // the tab, and came back a day later without typing the code.
            'email_verified' => null !== $user->email_verified_at,
            'two_factor' => $user->hasTwoFactor(),
            'must_set_up_two_factor' => $mustEnrol,
        ]);
    }

    /**
     * The second half of a sign-in.
     *
     * The challenge is spent whatever the answer: a handle that survived a wrong guess would be a
     * handle worth guessing against, and there is no cost to typing the password again.
     */
    public function twoFactor(Request $request)
    {
        $data = $request->validate([
            'challenge' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:20'],
        ]);

        $key = 'login:2fa:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw new ApiException('too_many_attempts', 'Too many attempts. Try again shortly.', 429, [], null, [
                'seconds' => RateLimiter::availableIn($key),
            ]);
        }

        $pending = Cache::pull('2fa:'.$data['challenge']);

        if (! $pending) {
            RateLimiter::hit($key, 900);

            throw ApiException::unauthorized('challenge_expired', 'Please sign in again.');
        }

        $user = $this->tenantContext->runUnscoped(fn () => User::find($pending['user_id']));

        if (! $user || ! app(TwoFactor::class)->verify($user, $data['code'])) {
            RateLimiter::hit($key, 900);

            throw ApiException::unauthorized('invalid_code', 'That code is not right.');
        }

        RateLimiter::clear($key);

        $membership = $user->memberships()->first();
        $tenant = $this->tenantContext->runUnscoped(fn () => Tenant::find($membership?->tenant_id));

        return response()->json([
            'token' => $user->createToken($pending['device'] ?? 'api')->plainTextToken,
            'tenant' => [
                'id' => $tenant?->id,
                'name' => $tenant?->name,
                'slug' => $tenant?->slug,
                'status' => $tenant?->status,
                'timezone' => $tenant?->timezone,
                'locale' => $tenant?->locale,
            ],
            'role' => $membership?->role,
            'permissions' => $membership ? $this->permissionsFor($membership, $tenant) : [],
            'agent' => $this->tenantContext->runAs($tenant, fn () => $this->agencyOf($user)),
            'programme_manager' => $this->tenantContext->runAs(
                $tenant,
                fn () => app(\App\Domain\Programme\EventManagers::class)->isOne($user),
            ),
            'email_verified' => null !== $user->email_verified_at,
            'two_factor' => true,
            'must_set_up_two_factor' => false,
        ]);
    }

    /**
     * The other half of a sign-in that happened somewhere else.
     *
     * The panel arrives holding a handle it was given in a redirect, and trades it for a token. The
     * handle is worth nothing on its own and worth nothing twice: it is pulled from the cache by
     * the first request that presents it, and it lasts a minute.
     *
     * No second factor is asked for, and that is the point of the feature rather than a gap in it.
     * The account has decided that its own provider is the authority on who its people are, and
     * that provider is where multi-factor now lives — asking again here would be this platform
     * second-guessing a directory it was told to believe.
     */
    public function claimSso(Request $request)
    {
        $data = $request->validate([
            'handoff' => ['required', 'string', 'max:200'],
            'device_name' => ['sometimes', 'string', 'max:100'],
        ]);

        $key = 'sso:claim:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 20)) {
            throw new ApiException('too_many_attempts', 'Too many attempts. Try again shortly.', 429, [], null, [
                'seconds' => RateLimiter::availableIn($key),
            ]);
        }

        $claimed = app(\App\Domain\Auth\SingleSignOn::class)->claimHandoff($data['handoff']);

        if (! $claimed) {
            RateLimiter::hit($key, 900);

            throw ApiException::unauthorized('sso_expired', 'That sign-in took too long. Please start again.');
        }

        $user = $this->tenantContext->runUnscoped(fn () => User::find($claimed['user_id']));
        $tenant = $this->tenantContext->runUnscoped(fn () => Tenant::find($claimed['tenant_id']));

        $membership = $user && $tenant
            ? $this->tenantContext->runUnscoped(fn () => TenantUser::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('user_id', $user->id)
                ->first())
            : null;

        // Membership is read again rather than carried in the handle: somebody can be removed from
        // a team between being redirected and coming back, and the answer that counts is now.
        if (! $user || ! $tenant || ! $tenant->isActive() || ! $membership) {
            throw ApiException::unauthorized('sso_expired', 'That sign-in took too long. Please start again.');
        }

        RateLimiter::clear($key);

        return response()->json([
            'token' => $user->createToken($data['device_name'] ?? 'api')->plainTextToken,
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'status' => $tenant->status,
                'timezone' => $tenant->timezone,
                'locale' => $tenant->locale,
            ],
            'role' => $membership->role,
            'permissions' => $this->permissionsFor($membership, $tenant),
            'agent' => $this->tenantContext->runAs($tenant, fn () => $this->agencyOf($user)),
            'programme_manager' => $this->tenantContext->runAs(
                $tenant,
                fn () => app(\App\Domain\Programme\EventManagers::class)->isOne($user),
            ),
            'email_verified' => null !== $user->email_verified_at,
            'two_factor' => $user->hasTwoFactor(),
            // Never on this path: the provider is where the second factor lives now.
            'must_set_up_two_factor' => false,
        ]);
    }

    /**
     * Who is holding this token, and what they may do with it.
     *
     * The panel asks on every boot rather than trusting what it stored at sign-in, because a role
     * can be narrowed while somebody has the tab open and a screen offered after that is a screen
     * that refuses when they reach it.
     */
    public function me(Request $request)
    {
        $user = $request->user();
        $membership = $request->attributes->get('membership');
        $tenant = $this->tenantContext->get();

        return response()->json([
            'email' => $user->email,
            'name' => $user->name,
            'tenant' => [
                'id' => $tenant?->id,
                'name' => $tenant?->name,
                'slug' => $tenant?->slug,
                'status' => $tenant?->status,
                'timezone' => $tenant?->timezone,
                'locale' => $tenant?->locale,
            ],
            'role' => $membership?->role,
            'permissions' => $this->gate->permissions($request),
            /*
             * Which agency this person sells for, when they sell for one.
             *
             * Not a permission — it is who they are, not what they may do — so it travels beside
             * the list rather than in it. The panel needs it to offer somebody their own account,
             * and an owner holds every permission there is without being anybody's agency.
             */
            'agent' => $this->agencyOf($user),
            /*
             * And whether they run nights rather than the account.
             *
             * Not a permission either — a programme manager holds `orders.view` like the box office
             * does, and the difference is which bookings that reaches. The panel needs to know so
             * it can leave out the screens that are about the account: the customer directory, the
             * season's settlement, the report builder.
             */
            'programme_manager' => app(\App\Domain\Programme\EventManagers::class)->isOne($user),
            'email_verified' => null !== $user->email_verified_at,
            'two_factor' => $user->hasTwoFactor(),
            'must_set_up_two_factor' => (bool) ($tenant?->require_two_factor) && ! $user->hasTwoFactor(),
        ]);
    }

    /**
     * The agency this person sells for, named rather than confirmed.
     *
     * Enough for the panel to offer them their own account and put their agency's name on it, and
     * no more: what the account holds is asked for separately, by an endpoint that resolves the
     * agency from the caller again rather than trusting anything the browser sends back.
     *
     * @return array{id: string, name: string, code: string}|null
     */
    private function agencyOf(User $user): ?array
    {
        $agent = app(\App\Domain\Agents\SalesAgents::class)->forUser($user);

        return $agent ? ['id' => $agent->id, 'name' => $agent->name, 'code' => $agent->code] : null;
    }

    /**
     * What this membership may do, resolved the same way every request resolves it.
     *
     * Inside the tenant, because a role the organiser invented is a row in their own table and a
     * lookup outside the scope would find nothing — which would quietly hand a custom role an
     * empty panel instead of their own.
     *
     * @return list<string>
     */
    private function permissionsFor(TenantUser $membership, ?Tenant $tenant): array
    {
        return $this->tenantContext->runAs($tenant, fn () => $this->gate->forMembership($membership));
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
