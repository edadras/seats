<?php

namespace App\Domain\Auth;

use App\Models\User;
use App\Support\Auth\Totp;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Setting up, checking and turning off a second step.
 *
 * Two kinds of credential, treated differently on purpose. The TOTP secret is encrypted, because
 * the server has to read it back to compute the code. Recovery codes are hashed, because they are
 * one-shot passwords and the server only ever has to answer "is this one of them".
 *
 * A recovery code is spent when it is used. Somebody who has lost their phone gets in, and the code
 * that let them in cannot let anybody else in afterwards.
 */
class TwoFactor
{
    public const RECOVERY_CODES = 8;

    /** Begin. Nothing is switched on until a code from the app has been typed back. */
    public function begin(User $user, string $issuer): array
    {
        $secret = Totp::secret();

        $user->forceFill([
            'totp_secret' => $secret,
            // Deliberately not confirmed: an enrolment abandoned halfway must leave the account
            // exactly as usable as it was.
            'totp_confirmed_at' => null,
        ])->save();

        return [
            'secret' => $secret,
            'uri' => Totp::uri($secret, $user->email, $issuer),
        ];
    }

    /**
     * Finish, by proving the app works.
     *
     * @return list<string>|null the recovery codes, shown exactly once, or null if the code is wrong
     */
    public function confirm(User $user, string $code): ?array
    {
        if (! $user->totp_secret || ! Totp::check($user->totp_secret, $code)) {
            return null;
        }

        $codes = $this->makeRecoveryCodes();

        $user->forceFill([
            'totp_confirmed_at' => now(),
            'recovery_codes' => array_map(fn (string $one) => Hash::make($one), $codes),
        ])->save();

        return $codes;
    }

    /**
     * Whether this is a valid second step: a code from the app, or one of the recovery codes.
     *
     * A recovery code is spent as it is used, which is what makes it a recovery code rather than a
     * second password.
     */
    public function verify(User $user, string $code): bool
    {
        if ($user->totp_secret && Totp::check($user->totp_secret, $code)) {
            return true;
        }

        $codes = (array) ($user->recovery_codes ?? []);
        $tidy = Str::upper(preg_replace('/[^A-Za-z0-9-]/', '', $code) ?? '');

        foreach ($codes as $index => $hash) {
            if (Hash::check($tidy, $hash)) {
                unset($codes[$index]);
                $user->forceFill(['recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }

    /** Turn it off. The caller takes a working second step first, so a borrowed tab cannot. */
    public function disable(User $user): void
    {
        $user->forceFill([
            'totp_secret' => null,
            'totp_confirmed_at' => null,
            'recovery_codes' => null,
        ])->save();
    }

    /** @return list<string> */
    public function makeRecoveryCodes(): array
    {
        return array_map(
            // Two short groups rather than one long string: these get written on paper, and a dash
            // is where the eye rests.
            fn () => Str::upper(Str::random(5).'-'.Str::random(5)),
            range(1, self::RECOVERY_CODES),
        );
    }
}
