<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\ApiKey;
use App\Support\Signing\HmacSigner;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;

/**
 * Server-to-server authentication for the storefront plugin.
 *
 * Four headers are required and all four are checked before anything touches the database beyond
 * the key lookup:
 *   X-Seatmap-Key        public key id
 *   X-Seatmap-Timestamp  unix seconds, must be within the replay window
 *   X-Seatmap-Nonce      unique within that window
 *   X-Seatmap-Signature  HMAC-SHA256 over method, path, timestamp, nonce and body hash
 */
class AuthenticateApiClient
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next)
    {
        $keyId = $request->header('X-Seatmap-Key');
        $timestamp = $request->header('X-Seatmap-Timestamp');
        $nonce = $request->header('X-Seatmap-Nonce');
        $signature = $request->header('X-Seatmap-Signature');

        if (! $keyId || ! $timestamp || ! $nonce || ! $signature) {
            throw ApiException::unauthorized(
                'missing_credentials',
                'X-Seatmap-Key, X-Seatmap-Timestamp, X-Seatmap-Nonce and X-Seatmap-Signature are all required.'
            );
        }

        $window = (int) config('seatmap.hmac_window_seconds');

        if (! ctype_digit((string) $timestamp) || abs(time() - (int) $timestamp) > $window) {
            throw ApiException::unauthorized(
                'stale_timestamp',
                "Request timestamp is outside the permitted ±{$window}s window."
            );
        }

        if (! preg_match('/^[A-Za-z0-9_.\-]{8,128}$/', $nonce)) {
            throw ApiException::unauthorized('invalid_nonce', 'Nonce format is not acceptable.');
        }

        // The key lookup deliberately bypasses tenant scoping — this is how the tenant is
        // discovered in the first place.
        $key = $this->tenantContext->runUnscoped(
            fn () => ApiKey::with('client.tenant')->where('key_id', $keyId)->first()
        );

        if (! $key || ! $key->isUsable()) {
            throw ApiException::unauthorized('invalid_key', 'API key is unknown, expired or revoked.');
        }

        $client = $key->client;

        if (! $client || ! $client->isActive() || ! $client->tenant?->isActive()) {
            throw ApiException::unauthorized('client_disabled', 'This API client is not active.');
        }

        $canonical = HmacSigner::canonicalString(
            $request->getMethod(),
            $request->getPathInfo(),
            (string) $timestamp,
            (string) $nonce,
            $request->getContent(),
        );

        // The stored value is a hash of the secret, and HMAC needs the secret itself. We therefore
        // sign with the *hash* as the key: the plugin does the same, so both sides derive the same
        // value while the plaintext secret never has to be stored here.
        if (! HmacSigner::verify($key->secret_hash, $canonical, $signature)) {
            throw ApiException::unauthorized('invalid_signature', 'Signature verification failed.');
        }

        $this->rejectReplay($key->id, $nonce, $window);

        $this->tenantContext->set($client->tenant);
        $request->attributes->set('api_key', $key);
        $request->attributes->set('api_client', $client);

        // Cheap liveness signal for the tenant's connection screen; not on the hot path of a scan.
        $key->forceFill(['last_used_at' => now()])->saveQuietly();

        return $next($request);
    }

    /**
     * A nonce may be used once per key inside the replay window. Redis SET NX is atomic, so two
     * simultaneous replays cannot both succeed.
     */
    private function rejectReplay(string $keyId, string $nonce, int $window): void
    {
        $cacheKey = "seatmap:nonce:{$keyId}:".hash('sha256', $nonce);

        try {
            $stored = Redis::set($cacheKey, '1', 'EX', $window, 'NX');
        } catch (\Throwable $e) {
            // Redis being down must not silently disable replay protection.
            throw new ApiException(
                'replay_check_unavailable',
                'Replay protection is temporarily unavailable; retry shortly.',
                503
            );
        }

        if (! $stored) {
            throw ApiException::unauthorized('nonce_reused', 'This nonce has already been used.');
        }
    }
}
