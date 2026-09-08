<?php

namespace App\Domain\Sites\Payments;

use App\Models\Site;
use RuntimeException;

/**
 * The gateways a site may offer.
 *
 * Registered by key rather than resolved from a class name in the database: a site's settings are
 * organiser input, and turning organiser input into a class to instantiate is how an admin panel
 * becomes a remote shell.
 */
class GatewayRegistry
{
    /** @var array<string, PaymentGateway> */
    private array $gateways = [];

    public function __construct()
    {
        $this->register(new OfflineGateway());
    }

    public function register(PaymentGateway $gateway): void
    {
        $this->gateways[$gateway->key()] = $gateway;
    }

    public function has(string $key): bool
    {
        return isset($this->gateways[$key]);
    }

    public function get(string $key): PaymentGateway
    {
        if (! isset($this->gateways[$key])) {
            throw new RuntimeException(sprintf('Unknown payment gateway [%s].', $key));
        }

        return $this->gateways[$key];
    }

    /** @return array<int, PaymentGateway> */
    public function enabledFor(Site $site): array
    {
        $keys = $site->brand['gateways'] ?? null;
        $keys = is_array($keys) && $keys ? $keys : ['offline'];

        return array_values(array_filter(array_map(
            fn (string $key) => $this->gateways[$key] ?? null,
            array_values(array_filter($keys, 'is_string'))
        )));
    }
}
