<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Auth\TwoFactor;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Support\Audit\AuditLogger;
use App\Support\Qr\QrRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * A second step at the door of an account that can move money.
 *
 * Everything here is about the signed-in person's own account. There is no way to set up, inspect
 * or remove somebody else's — an administrator who could would be an administrator who could sign
 * in as them, which is the thing this exists to prevent.
 *
 * Turning it off takes a working second step *and* the password, because a borrowed tab is the case
 * it defends against and a borrowed tab has neither.
 */
class TwoFactorController extends Controller
{
    public function __construct(
        private readonly TwoFactor $twoFactor,
        private readonly AuditLogger $audit,
        private readonly QrRenderer $qr,
    ) {}

    /** What the account currently has. */
    public function show(Request $request)
    {
        $user = $request->user();
        $tenant = $request->attributes->get('tenant');

        return response()->json([
            'enabled' => $user->hasTwoFactor(),
            'recovery_codes_left' => count((array) ($user->recovery_codes ?? [])),
            'required_by_account' => (bool) ($tenant?->require_two_factor),
        ]);
    }

    /**
     * Begin: a secret, and a QR code to point a phone at.
     *
     * Nothing is switched on here. The secret is written down so the confirm step can check against
     * it, and an enrolment abandoned halfway leaves the account exactly as usable as it was.
     */
    public function begin(Request $request)
    {
        $user = $request->user();
        $tenant = $request->attributes->get('tenant');

        $started = $this->twoFactor->begin($user, $tenant?->name ?: config('app.name'));

        return response()->json([
            'secret' => $started['secret'],
            'uri' => $started['uri'],
            // The same renderer the tickets use. A secret typed by hand from a phone screen is a
            // secret typed wrongly.
            'qr' => $this->qr->dataUri($started['uri'], 220),
        ]);
    }

    /** Finish, by proving the app works. The recovery codes are returned exactly once. */
    public function confirm(Request $request)
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);

        $codes = $this->twoFactor->confirm($request->user(), $data['code']);

        if (null === $codes) {
            throw ApiException::unprocessable('invalid_code', 'That code is not right. Try the next one.');
        }

        $this->audit->record('user.two_factor_enabled', $request->user());

        return response()->json([
            'enabled' => true,
            // Shown once and never again: they are hashed the moment they are made.
            'recovery_codes' => $codes,
        ]);
    }

    /** A fresh set, for somebody who has used most of theirs or lost the paper. */
    public function recoveryCodes(Request $request)
    {
        $user = $request->user();

        if (! $user->hasTwoFactor()) {
            throw ApiException::conflict('two_factor_off', 'Two-step sign-in is not switched on.');
        }

        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);

        if (! $this->twoFactor->verify($user, $data['code'])) {
            throw ApiException::unprocessable('invalid_code', 'That code is not right.');
        }

        $codes = $this->twoFactor->makeRecoveryCodes();

        $user->forceFill([
            'recovery_codes' => array_map(fn (string $one) => Hash::make($one), $codes),
        ])->save();

        $this->audit->record('user.recovery_codes_replaced', $user);

        return response()->json(['recovery_codes' => $codes]);
    }

    public function disable(Request $request)
    {
        $user = $request->user();
        $tenant = $request->attributes->get('tenant');

        $data = $request->validate([
            'code' => ['required', 'string', 'max:20'],
            'password' => ['required', 'string'],
        ]);

        if ($tenant?->require_two_factor) {
            throw ApiException::conflict(
                'two_factor_required',
                'This account requires two-step sign-in, so it cannot be turned off.'
            );
        }

        // A working second step and the password: a borrowed tab is the case this defends against,
        // and a borrowed tab has neither.
        if (! Hash::check($data['password'], $user->password) || ! $this->twoFactor->verify($user, $data['code'])) {
            throw ApiException::unprocessable('invalid_code', 'That code or password is not right.');
        }

        $this->twoFactor->disable($user);
        $this->audit->record('user.two_factor_disabled', $user);

        return response()->json(['enabled' => false]);
    }

    /** An owner requiring it of everybody. */
    public function require(Request $request)
    {
        $this->authorize($request, 'account.manage');

        $data = $request->validate(['required' => ['required', 'boolean']]);
        $tenant = $request->attributes->get('tenant');

        if ($data['required'] && ! $request->user()->hasTwoFactor()) {
            // Requiring it of everybody while not having it yourself is how an account locks its
            // own owner out on the next sign-in.
            throw ApiException::conflict(
                'set_up_yours_first',
                'Set up two-step sign-in on your own account before requiring it of everybody.'
            );
        }

        $tenant->forceFill(['require_two_factor' => $data['required']])->save();

        $this->audit->record('account.two_factor_requirement', $tenant, [
            'required' => $data['required'],
        ]);

        return response()->json(['required_by_account' => (bool) $tenant->require_two_factor]);
    }
}
