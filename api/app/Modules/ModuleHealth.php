<?php

namespace App\Modules;

use App\Models\ModuleFailure;
use App\Models\TenantModule;
use App\Support\Tenancy\TenantContext;

/**
 * Whether a module is working, and what to do when it repeatedly is not (ADR-0004 §5).
 *
 * The rule this encodes: a module may fail, and failing must not take a request down — but it must
 * never fail *silently and forever*. A messaging module that quietly stopped sending tickets looks
 * exactly like one that is working, right up to the evening three hundred people arrive without
 * their codes.
 *
 * So failures are counted, and past a threshold the module is switched off for that tenant with a
 * reason the organiser can read. Being off is visible. Being broken is not.
 */
class ModuleHealth
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /** @return array{failures:int, last_failure_at:?string, last_message:?string} */
    public function forModule(string $moduleKey): array
    {
        $since = now()->subHours($this->window());

        $failures = ModuleFailure::where('module_key', $moduleKey)
            ->where('created_at', '>=', $since)
            ->orderByDesc('created_at')
            ->get(['message', 'created_at']);

        return [
            'failures' => $failures->count(),
            'last_failure_at' => $failures->first()?->created_at?->toIso8601String(),
            'last_message' => $failures->first()?->message,
        ];
    }

    /**
     * Called after a failure is recorded. Switches the module off for this tenant if it has failed
     * too often too recently.
     *
     * Deliberately not a hair trigger: a gateway is allowed a bad afternoon. What it is not allowed
     * is a bad fortnight nobody notices.
     */
    public function reviewAfterFailure(string $moduleKey): bool
    {
        $tenantId = $this->tenantContext->id();

        if (! $tenantId) {
            return false;
        }

        $recent = ModuleFailure::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('module_key', $moduleKey)
            ->where('created_at', '>=', now()->subHours($this->window()))
            ->count();

        if ($recent < $this->threshold()) {
            return false;
        }

        $row = TenantModule::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('module_key', $moduleKey)
            ->first();

        // A module that is already off, or that was never explicitly on, needs no action. The
        // auto-enable case does need a row: "off, and here is why" has to be recorded somewhere.
        if ($row && ! $row->enabled) {
            return false;
        }

        TenantModule::withoutGlobalScope('tenant')->updateOrCreate(
            ['tenant_id' => $tenantId, 'module_key' => $moduleKey],
            [
                'enabled' => false,
                'disabled_reason' => 'repeated_failures',
                'disabled_at' => now(),
                'settings' => $row->settings ?? [],
            ],
        );

        ModuleRegistry::forget($tenantId);

        logger()->warning('Module switched off after repeated failures.', [
            'module' => $moduleKey,
            'tenant' => $tenantId,
            'failures' => $recent,
        ]);

        return true;
    }

    private function threshold(): int
    {
        return max(1, (int) config('seatmap.modules.max_failures', 20));
    }

    private function window(): int
    {
        return max(1, (int) config('seatmap.modules.failure_window_hours', 24));
    }
}
