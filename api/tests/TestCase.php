<?php

namespace Tests;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function tearDown(): void
    {
        // Tenant binding is a singleton for the life of the process; leaking it between tests
        // would let one test read another's fixtures and mask a real isolation bug.
        if ($this->app) {
            $this->app->make(TenantContext::class)->set(null);
        }

        parent::tearDown();
    }

    /** Run a closure with a tenant bound, as a request would. */
    protected function asTenant(\App\Models\Tenant $tenant, callable $callback): mixed
    {
        return $this->app->make(TenantContext::class)->runAs($tenant, $callback);
    }
}
