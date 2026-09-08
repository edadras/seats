<?php

namespace Tests\Support;

use App\Models\Event;
use App\Models\Tenant;
use Illuminate\Testing\TestResponse;

/**
 * Makes a test behave like the WordPress plugin: signed server-to-server calls, plus the
 * hold-then-register setup every order test otherwise repeats.
 */
trait ActsAsStorefront
{
    protected array $storefrontApi;

    protected string $holdToken;

    /**
     * Set up a tenant with a sellable event, hold two seats and register an order against them.
     *
     * @return array{tenant: Tenant, event: Event, seats: \Illuminate\Support\Collection}
     */
    protected function sellableOrder(string $externalOrderId, ?Tenant $tenant = null): array
    {
        $ctx = $this->makeSellableEvent($tenant);
        $this->storefrontApi = $this->makeApiClient($ctx['tenant']);

        $hold = $this->postJson("/v1/embed/events/{$ctx['event']->public_id}/holds", [
            'seat_ids' => [$ctx['seats'][0]->id, $ctx['seats'][1]->id],
            'session_id' => 'session-'.$externalOrderId,
        ])->assertCreated();

        $this->holdToken = $hold->json('hold_token');

        $this->storefront('POST', '/v1/integrations/woocommerce/orders', [
            'external_order_id' => $externalOrderId,
            'hold_token' => $this->holdToken,
        ])->assertCreated();

        return $ctx;
    }

    /** Issue a signed request the way the plugin does. */
    protected function storefront(
        string $method,
        string $path,
        array $payload = [],
        ?string $idempotencyKey = null,
        ?array $overrideHeaders = null,
    ): TestResponse {
        $body = $payload === [] && $method !== 'GET' ? '{}' : ($method === 'GET' ? '' : json_encode($payload));

        $headers = $overrideHeaders ?? $this->signedHeaders(
            $this->storefrontApi['key_id'],
            $this->storefrontApi['secret'],
            $method,
            $path,
            $body,
        );

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call($method, $path, [], [], [], $server, $body === '' ? null : $body);
    }
}
