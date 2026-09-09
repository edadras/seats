<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

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
                throw new ApiException('too_many_attempts', sprintf(
                    'Too many login attempts. Try again in %d seconds.', RateLimiter::availableIn($key)
                ), 429);
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
            // So the panel can put the verification bar back for somebody who signed up, closed
            // the tab, and came back a day later without typing the code.
            'email_verified' => null !== $user->email_verified_at,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
