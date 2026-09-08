<?php

namespace App\Domain\Sites\Payments;

use App\Models\Site;
use App\Modules\ModuleRegistry;
use RuntimeException;

/**
 * The gateways a site may offer.
 *
 * Registered by key rather than resolved from a class name in the database: a site's settings are
 * organiser input, and turning organiser input into a class to instantiate is how an admin panel
 * becomes a remote shell.
 *
 * Every gateway now arrives from a module (ADR-0004), the offline one included. Core knows what a
 * gateway *is* and nothing about any particular one — which is the test of whether the module
 * system is good enough to offer to somebody else.
 */
class GatewayRegistry
{
    /** @var array<string, PaymentGateway>|null Built lazily, per tenant. */
    private ?array $gateways = null;

    private ?string $builtFor = null;

    public function __construct(private readonly ModuleRegistry $modules) {}

    /**
     * @return array<string, PaymentGateway>
     */
    private function gateways(): array
    {
        $tenantId = app(\App\Support\Tenancy\TenantContext::class)->id();

        // Gateways carry a tenant's own credentials, so the set is rebuilt when the tenant changes.
        // A gateway built for one organiser must never take money into another's account.
        if (null !== $this->gateways && $this->builtFor === $tenantId) {
            return $this->gateways;
        }

        $gateways = [];

        foreach ($this->modules->contributions('payments') as $gateway) {
            if ($gateway instanceof PaymentGateway) {
                $gateways[$gateway->key()] = $gateway;
            }
        }

        $this->builtFor = $tenantId;

        return $this->gateways = $gateways;
    }

    /** Used by tests and by a module that wants to add a gateway without a manifest. */
    public function register(PaymentGateway $gateway): void
    {
        $this->gateways = $this->gateways() + [];
        $this->gateways[$gateway->key()] = $gateway;
    }

    public function has(string $key): bool
    {
        return isset($this->gateways()[$key]);
    }

    public function get(string $key): PaymentGateway
    {
        $gateways = $this->gateways();

        if (! isset($gateways[$key])) {
            throw new RuntimeException(sprintf('Unknown payment gateway [%s].', $key));
        }

        return $gateways[$key];
    }

    /** @return array<int, PaymentGateway> */
    public function all(): array
    {
        return array_values($this->gateways());
    }

    /**
     * The gateways this site offers, in the order the organiser chose.
     *
     * A gateway named in a site's settings whose module is no longer enabled simply is not here.
     * That is the right behaviour: switching a payment module off must stop it appearing at a
     * checkout, and it must not need every site that mentioned it to be edited first.
     *
     * @return array<int, PaymentGateway>
     */
    public function enabledFor(Site $site): array
    {
        $gateways = $this->gateways();
        $keys = $site->brand['gateways'] ?? null;
        $keys = is_array($keys) && $keys ? array_values(array_filter($keys, 'is_string')) : [];

        // No choice recorded means "whatever is on", so a site keeps working the moment its
        // organiser enables a module, without a second trip to the site settings.
        if (! $keys) {
            return array_values($gateways);
        }

        return array_values(array_filter(array_map(
            fn (string $key) => $gateways[$key] ?? null,
            $keys
        )));
    }
}
