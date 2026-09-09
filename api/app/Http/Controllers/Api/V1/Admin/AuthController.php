<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Signing in to the console.
 *
 * A route of its own rather than a flag on the panel's login, because the two answer different
 * questions: the panel's asks "which organiser is this person a member of", and an operator is a
 * member of none. Sharing that endpoint would mean one function deciding both, and that function
 * would be the most security-sensitive twenty lines in the codebase.
 *
 * Signing in here is itself recorded. Somebody who can read every organiser's data should leave a
 * trail from the moment they arrive, not from the first thing they change.
 */
class AuthController extends Controller
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $keys = [
            'console:email:'.mb_strtolower($data['email']),
            'console:ip:'.$request->ip(),
        ];

        foreach ($keys as $key) {
            if (RateLimiter::tooManyAttempts($key, 10)) {
                throw new ApiException('too_many_attempts', sprintf(
                    'Too many attempts. Try again in %d seconds.', RateLimiter::availableIn($key)
                ), 429);
            }
        }

        [$user, $admin] = $this->tenants->runUnscoped(function () use ($data) {
            $user = User::where('email', mb_strtolower($data['email']))->first();

            return [$user, $user ? PlatformAdmin::where('user_id', $user->id)->first() : null];
        });

        // One message for all three cases — no such user, wrong password, not an operator — so
        // this endpoint cannot be used to find out who runs the platform.
        if (! $user || ! $admin || ! Hash::check($data['password'], $user->password)) {
            foreach ($keys as $key) {
                RateLimiter::hit($key, 900);
            }

            throw ApiException::unauthorized('invalid_credentials', 'These credentials do not match our records.');
        }

        foreach ($keys as $key) {
            RateLimiter::clear($key);
        }

        PlatformAuditLog::write($user->id, 'console.signed_in', null, [], $request->ip());

        return response()->json([
            // Four hours, not a fortnight: this token reads every organiser's data, and a console
            // left open on a laptop is the most valuable thing on it.
            'token' => $user->createToken('console', ['*'], now()->addHours(4))->plainTextToken,
            'level' => $admin->level,
            'name' => $user->name,
        ]);
    }
}
