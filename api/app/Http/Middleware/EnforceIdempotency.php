<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\IdempotencyKey;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Replay protection for mutating endpoints.
 *
 * The plugin lives on the far side of an unreliable network; when it does not hear back it must be
 * able to retry without risking a second sale. So: the first request for a key runs and its
 * response is stored; retries replay that response byte for byte.
 *
 * The reservation row is inserted in its own committed transaction *before* the handler runs, so
 * two simultaneous retries cannot both get past it — the second hits the unique index.
 */
class EnforceIdempotency
{
    private const RETENTION_HOURS = 24;

    public function __construct(private readonly TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next)
    {
        $key = $request->header('Idempotency-Key');

        if (! $key) {
            return $next($request);
        }

        if (strlen($key) > 255) {
            throw ApiException::unprocessable('invalid_idempotency_key', 'Idempotency-Key is too long.');
        }

        $scope = $this->scopeFor($request);
        $requestHash = hash('sha256', $request->getMethod().'|'.$request->getPathInfo().'|'.$request->getContent());

        $existing = $this->tenantContext->runUnscoped(
            fn () => IdempotencyKey::where('scope', $scope)->where('idempotency_key', $key)->first()
        );

        if ($existing) {
            return $this->replay($existing, $requestHash);
        }

        try {
            $record = $this->tenantContext->runUnscoped(fn () => IdempotencyKey::create([
                'tenant_id' => $this->tenantContext->id(),
                'scope' => $scope,
                'idempotency_key' => $key,
                'request_hash' => $requestHash,
                'endpoint' => $request->getMethod().' '.$request->getPathInfo(),
                'expires_at' => now()->addHours(self::RETENTION_HOURS),
            ]));
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // Another copy of this request won the race and is executing right now. Telling the
            // caller to retry is safer than running the handler a second time.
            throw ApiException::conflict(
                'idempotency_key_in_flight',
                'A request with this Idempotency-Key is currently being processed. Retry shortly.'
            );
        }

        $response = $next($request);

        if ($response->getStatusCode() < 500) {
            $body = json_decode($response->getContent(), true);

            $this->tenantContext->runUnscoped(fn () => $record->forceFill([
                'response_status' => $response->getStatusCode(),
                'response_body' => is_array($body) ? $body : null,
                'completed_at' => now(),
            ])->save());
        } else {
            // A server error is not a durable outcome — drop the reservation so a retry can run.
            $this->tenantContext->runUnscoped(fn () => $record->delete());
        }

        return $response;
    }

    private function replay(IdempotencyKey $record, string $requestHash): mixed
    {
        if (! hash_equals($record->request_hash, $requestHash)) {
            throw ApiException::conflict(
                'idempotency_key_reuse',
                'This Idempotency-Key was already used with a different request body.'
            );
        }

        if ($record->completed_at === null) {
            throw ApiException::conflict(
                'idempotency_key_in_flight',
                'A request with this Idempotency-Key is currently being processed. Retry shortly.'
            );
        }

        return response()
            ->json($record->response_body ?? [], $record->response_status ?? 200)
            ->header('Idempotent-Replay', 'true');
    }

    /**
     * Keys are namespaced per caller so two tenants — or a tenant and an anonymous widget — cannot
     * collide on a generic key like "1".
     */
    private function scopeFor(Request $request): string
    {
        if ($key = $request->attributes->get('api_key')) {
            return 'api_client:'.$key->api_client_id;
        }

        if ($device = $request->attributes->get('checkin_device')) {
            return 'device:'.$device->id;
        }

        if ($user = $request->user()) {
            return 'user:'.$user->getKey().':'.($this->tenantContext->id() ?? 'none');
        }

        return 'ip:'.$request->ip();
    }
}
