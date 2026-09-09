<?php

namespace App\Support\Auth;

/**
 * Time-based one-time passwords, RFC 6238.
 *
 * A hundred lines rather than a dependency, because that is genuinely all it is: a counter of
 * thirty-second steps, an HMAC of it, and four bytes read out of the digest. A package would be a
 * supply chain for something this small, and every authenticator app on a phone implements the
 * same document.
 *
 * SHA-1 and six digits are not a weak choice here; they are the choice every authenticator app
 * assumes, and a server that picked something stronger would be a server nobody could enrol on.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const STEP = 30;
    private const DIGITS = 6;

    /** A fresh secret, in the base32 an authenticator app expects to be handed. */
    public static function secret(int $bytes = 20): string
    {
        return self::base32(random_bytes($bytes));
    }

    /**
     * Whether a code is right, allowing for clocks that disagree.
     *
     * One step either side: a phone whose clock is twenty seconds out is an ordinary phone, and
     * refusing it would be blaming somebody for a fact about the world. Wider than that starts
     * meaningfully lengthening the window an intercepted code stays useful in.
     */
    public static function check(string $secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($code) !== self::DIGITS) {
            return false;
        }

        $counter = intdiv(time(), self::STEP);

        for ($drift = -$window; $drift <= $window; $drift++) {
            // hash_equals, not ===: a short-circuiting comparison leaks how much of a guess was
            // right through how long the comparison took.
            if (hash_equals(self::at($secret, $counter + $drift), $code)) {
                return true;
            }
        }

        return false;
    }

    /** The code for one thirty-second step. */
    public static function at(string $secret, int $counter): string
    {
        $key = self::unbase32($secret);
        $digest = hash_hmac('sha1', pack('J', $counter), $key, true);

        // Dynamic truncation: the low nibble of the last byte says where to read from.
        $offset = ord($digest[19]) & 0x0F;
        $value = ((ord($digest[$offset]) & 0x7F) << 24)
            | (ord($digest[$offset + 1]) << 16)
            | (ord($digest[$offset + 2]) << 8)
            | ord($digest[$offset + 3]);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * The URI an authenticator app reads out of a QR code.
     *
     * The issuer appears twice — in the label and as a parameter — because apps disagree about
     * which one they read, and an account that shows up as "unknown" in somebody's list is an
     * account they delete by accident six months later.
     */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer.':'.$account).'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::STEP,
        ]);
    }

    private static function base32(string $bytes): string
    {
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';

        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $out;
    }

    private static function unbase32(string $secret): string
    {
        $bits = '';

        foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $secret) ?? '')) as $char) {
            $bits .= str_pad(decbin((int) strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';

        foreach (str_split($bits, 8) as $chunk) {
            if (8 === strlen($chunk)) {
                $bytes .= chr(bindec($chunk));
            }
        }

        return $bytes;
    }
}
