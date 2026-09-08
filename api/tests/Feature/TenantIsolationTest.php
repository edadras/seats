<?php

namespace Tests\Feature;

use App\Models\Seat;
use App\Models\Tenant;
use App\Models\Venue;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ActsAsStorefront;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * Threat T1. Two organisers share one database; neither may see or touch the other's data.
 *
 * These tests use tenant A's real, valid credentials against tenant B's real, valid ids — the
 * case an ordinary permission check would wave through.
 */
class TenantIsolationTest extends TestCase
{
    use ActsAsStorefront, BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function a_tenants_api_token_cannot_read_another_tenants_resources(): void
    {
        $a = $this->makeSellableEvent($this->makeTenant('Alpha'));
        $b = $this->makeSellableEvent($this->makeTenant('Beta'));

        $token = $this->tokenFor($a['tenant']);

        // Every one of these is a valid id — just not this caller's.
        $this->withToken($token)->getJson("/v1/venues/{$b['venue']->id}")->assertNotFound();
        $this->withToken($token)->getJson("/v1/seat-maps/{$b['map']->id}")->assertNotFound();
        $this->withToken($token)->getJson("/v1/events/{$b['event']->id}")->assertNotFound();
        $this->withToken($token)->getJson("/v1/events/{$b['event']->id}/stats")->assertNotFound();
        $this->withToken($token)->getJson("/v1/events/{$b['event']->id}/checkins")->assertNotFound();
    }

    #[Test]
    public function listings_never_leak_another_tenants_rows(): void
    {
        $a = $this->makeSellableEvent($this->makeTenant('Alpha'));
        $b = $this->makeSellableEvent($this->makeTenant('Beta'));

        $token = $this->tokenFor($a['tenant']);

        $events = $this->withToken($token)->getJson('/v1/events')->assertOk()->json('data');
        $venues = $this->withToken($token)->getJson('/v1/venues')->assertOk()->json('data');

        $this->assertSame([$a['event']->id], array_column($events, 'id'));
        $this->assertSame([$a['venue']->id], array_column($venues, 'id'));
        $this->assertNotContains($b['event']->id, array_column($events, 'id'));
    }

    #[Test]
    public function a_tenant_cannot_mutate_another_tenants_resources(): void
    {
        $a = $this->makeSellableEvent($this->makeTenant('Alpha'));
        $b = $this->makeSellableEvent($this->makeTenant('Beta'));

        $token = $this->tokenFor($a['tenant']);

        $this->withToken($token)->patchJson("/v1/events/{$b['event']->id}", ['name' => 'Hijacked'])->assertNotFound();
        $this->withToken($token)->patchJson("/v1/venues/{$b['venue']->id}", ['name' => 'Hijacked'])->assertNotFound();
        $this->withToken($token)->deleteJson("/v1/venues/{$b['venue']->id}")->assertNotFound();
        $this->withToken($token)->postJson("/v1/seat-maps/{$b['map']->id}/publish")->assertNotFound();

        $this->assertSame('Opening night', $b['event']->fresh()->name);
    }

    #[Test]
    public function a_tenant_cannot_attach_its_map_to_another_tenants_venue(): void
    {
        $a = $this->makeTenant('Alpha');
        $b = $this->makeSellableEvent($this->makeTenant('Beta'));

        $this->makeSellableEvent($a); // give A something of its own

        $this->withToken($this->tokenFor($a))
            ->postJson('/v1/seat-maps', ['venue_id' => $b['venue']->id, 'name' => 'Sneaky'])
            ->assertNotFound();
    }

    #[Test]
    public function an_api_key_can_only_act_on_its_own_tenants_orders(): void
    {
        $b = $this->sellableOrder('wc_3001');            // tenant B's order
        $bApi = $this->storefrontApi;

        $a = $this->makeSellableEvent($this->makeTenant('Alpha'));
        $this->storefrontApi = $this->makeApiClient($a['tenant']);

        // Tenant A's storefront, correctly signed, asking about tenant B's order.
        $this->storefront('GET', '/v1/integrations/woocommerce/orders/wc_3001')->assertNotFound();
        $this->storefront('POST', '/v1/integrations/woocommerce/orders/wc_3001/confirm')->assertNotFound();
        $this->storefront('POST', '/v1/integrations/woocommerce/orders/wc_3001/refund')->assertNotFound();

        // B's own key still works, proving the order exists and it is only A that is shut out.
        $this->storefrontApi = $bApi;
        $this->storefront('GET', '/v1/integrations/woocommerce/orders/wc_3001')->assertOk();
    }

    #[Test]
    public function two_storefronts_of_the_same_tenant_cannot_touch_each_others_orders(): void
    {
        // A tenant with two shops is a supported setup, and one shop confirming the other's order
        // would be an accounting mess even though both belong to the same organiser.
        $ctx = $this->sellableOrder('wc_3002');
        $secondShop = $this->makeApiClient($ctx['tenant']);

        $this->storefrontApi = $secondShop;
        $this->storefront('GET', '/v1/integrations/woocommerce/orders/wc_3002')->assertNotFound();
    }

    #[Test]
    public function a_check_in_device_cannot_scan_an_event_it_was_not_granted(): void
    {
        $a = $this->makeSellableEvent($this->makeTenant('Alpha'));
        $b = $this->makeSellableEvent($this->makeTenant('Beta'));

        $device = $this->pairDevice($a['tenant'], $a['event']);

        // Explicitly unbind first: a real request starts with no tenant, and this test previously
        // passed only because the pairing request had left one bound in-process.
        app(TenantContext::class)->set(null);

        $this->withToken($device)
            ->postJson('/v1/checkin/scan', ['token' => 'TKTWHATEVER', 'event_id' => $b['event']->id])
            ->assertForbidden();
    }

    #[Test]
    public function a_device_authenticates_with_no_tenant_bound(): void
    {
        // Regression guard. Authentication has to work before a tenant is known, so the token
        // holder must be resolved unscoped; scoping it 401s every real request.
        $ctx = $this->makeSellableEvent($this->makeTenant('Alpha'));
        $device = $this->pairDevice($ctx['tenant'], $ctx['event']);

        app(TenantContext::class)->set(null);

        $this->withToken($device)->getJson('/v1/checkin/events')
            ->assertOk()
            ->assertJsonPath('data.0.id', $ctx['event']->id);
    }

    #[Test]
    public function the_model_layer_refuses_a_cross_tenant_write_even_without_http(): void
    {
        // Defence in depth: if a controller ever forgets to scope, the write itself must fail
        // rather than quietly landing in the wrong account.
        $a = $this->makeTenant('Alpha');
        $b = $this->makeTenant('Beta');

        $venueOfB = $this->asTenant($b, fn () => Venue::factory()->create(['tenant_id' => $b->id]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Refusing to write/');

        $this->asTenant($a, fn () => $venueOfB->update(['name' => 'Reassigned']));
    }

    #[Test]
    public function queries_return_nothing_when_no_tenant_is_bound(): void
    {
        // Failing closed matters most in queued jobs and console commands, where forgetting to
        // bind a tenant is easy. Returning every tenant's rows would be the worst possible default.
        $this->makeSellableEvent($this->makeTenant('Alpha'));

        app(TenantContext::class)->set(null);

        $this->assertSame(0, Venue::count());
        $this->assertSame(0, Seat::count());
    }

    private function tokenFor(Tenant $tenant): string
    {
        $user = $this->makeUser($tenant);

        return $this->postJson('/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->json('token');
    }

    private function pairDevice(Tenant $tenant, \App\Models\Event $event): string
    {
        return $this->asTenant($tenant, function () use ($tenant, $event) {
            $code = 'pair-'.\Illuminate\Support\Str::random(10);

            $device = \App\Models\CheckinDevice::factory()->create([
                'tenant_id' => $tenant->id,
                'status' => 'pending',
                'pairing_code_hash' => hash('sha256', $code),
                'pairing_expires_at' => now()->addMinutes(30),
            ]);

            $device->grantAccessTo($event);

            return $this->postJson('/v1/checkin/auth/token', [
                'pairing_code' => $code,
                'device_name' => 'Door 1',
            ])->assertOk()->json('token');
        });
    }
}
