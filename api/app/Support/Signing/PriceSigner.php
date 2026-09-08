<?php

namespace App\Support\Signing;

/**
 * Signs the price snapshot a storefront prices its cart from.
 *
 * This exists so a buyer cannot edit an amount in the browser (threat T3). It is an integrity
 * check on data in transit, not the source of truth: at confirm time the server re-reads the
 * hold's own stored snapshot and ignores whatever the client sent back.
 */
class PriceSigner
{
    public function __construct(private readonly string $key) {}

    /**
     * @return array{payload: string, signature: string, algorithm: string}
     */
    public function sign(array $payload): array
    {
        $encoded = $this->encode($payload);

        return [
            'payload' => $encoded,
            'signature' => hash_hmac('sha256', $encoded, $this->key),
            'algorithm' => 'HMAC-SHA256',
        ];
    }

    public function verify(string $payload, string $signature): bool
    {
        return hash_equals(hash_hmac('sha256', $payload, $this->key), $signature);
    }

    /** @return array<mixed>|null Null when the payload is unsigned, tampered with, or malformed. */
    public function decode(string $payload, string $signature): ?array
    {
        if (! $this->verify($payload, $signature)) {
            return null;
        }

        $json = base64_decode(strtr($payload, '-_', '+/'), true);

        if ($json === false) {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function encode(array $payload): string
    {
        // Canonical JSON: sorted keys so the same snapshot always encodes identically.
        $this->ksortRecursive($payload);

        return rtrim(strtr(base64_encode(
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        ), '+/', '-_'), '=');
    }

    private function ksortRecursive(array &$array): void
    {
        ksort($array);

        foreach ($array as &$value) {
            if (is_array($value)) {
                $this->ksortRecursive($value);
            }
        }
    }
}
