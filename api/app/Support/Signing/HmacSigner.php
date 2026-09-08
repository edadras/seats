<?php

namespace App\Support\Signing;

/**
 * Canonical signing for server-to-server calls.
 *
 * The method and path are part of the signed string on purpose: without them a captured signature
 * for `/cancel` could be replayed against `/confirm` (threat T4).
 */
class HmacSigner
{
    public static function canonicalString(
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $rawBody,
    ): string {
        return implode("\n", [
            strtoupper($method),
            $path,
            $timestamp,
            $nonce,
            hash('sha256', $rawBody),
        ]);
    }

    public static function sign(string $secret, string $canonical): string
    {
        return hash_hmac('sha256', $canonical, $secret);
    }

    public static function verify(string $secret, string $canonical, string $signature): bool
    {
        // hash_equals, not ===: signature comparison must not leak timing information.
        return hash_equals(self::sign($secret, $canonical), $signature);
    }
}
