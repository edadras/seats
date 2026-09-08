<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\CheckinDevice;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;

/**
 * Binds the tenant for scanner calls from the device's own token, so a scanner never needs — and
 * never gets — a staff member's panel credentials.
 */
class ResolveCheckinDevice
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next)
    {
        $device = $request->user();

        if (! $device instanceof CheckinDevice) {
            throw ApiException::unauthorized('unauthenticated', 'A check-in device token is required.', 'device_token_required');
        }

        if ($device->status !== 'active') {
            throw ApiException::forbidden('This device has been revoked.', 'device_revoked');
        }

        $tenant = $this->tenantContext->runUnscoped(fn () => $device->tenant()->first());

        if (! $tenant?->isActive()) {
            throw ApiException::forbidden('This organiser account is suspended.', 'tenant_suspended');
        }

        $this->tenantContext->set($tenant);
        $request->attributes->set('checkin_device', $device);

        $device->forceFill(['last_seen_at' => now()])->saveQuietly();

        return $next($request);
    }
}
