<?php

namespace App\Support\Tenancy;

use App\Models\Tenant;

/**
 * The tenant the current request (or job) acts for.
 *
 * Everything tenant-scoped reads from here rather than from the authenticated user, because the
 * same scoping must hold for API-key callers, queued jobs and console commands, none of which
 * have a logged-in user.
 */
class TenantContext
{
    private ?Tenant $tenant = null;

    /** Set to true only inside deliberate cross-tenant work (system jobs, admin tooling). */
    private bool $unscoped = false;

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?string
    {
        return $this->tenant?->id;
    }

    public function has(): bool
    {
        return $this->tenant !== null;
    }

    public function idOrFail(): string
    {
        if ($this->tenant === null) {
            throw new \RuntimeException('No tenant is bound to the current context.');
        }

        return $this->tenant->id;
    }

    public function isUnscoped(): bool
    {
        return $this->unscoped;
    }

    /**
     * Run a callback outside tenant scoping. Use sparingly and never on a path reachable by a
     * tenant-authenticated request — this deliberately disables the isolation guarantee.
     */
    public function runUnscoped(callable $callback): mixed
    {
        $previous = $this->unscoped;
        $this->unscoped = true;

        try {
            return $callback();
        } finally {
            $this->unscoped = $previous;
        }
    }

    /** Run a callback as a specific tenant, restoring the previous binding afterwards. */
    public function runAs(?Tenant $tenant, callable $callback): mixed
    {
        $previous = $this->tenant;
        $this->tenant = $tenant;

        try {
            return $callback();
        } finally {
            $this->tenant = $previous;
        }
    }
}
