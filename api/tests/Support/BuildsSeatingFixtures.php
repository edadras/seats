<?php

namespace Tests\Support;

use App\Domain\SeatMaps\SeatMapPublisher;
use App\Models\ApiClient;
use App\Models\ApiKey;
use App\Models\Event;
use App\Models\EventPriceZone;
use App\Models\Plan;
use App\Models\SeatMap;
use App\Models\SeatMapVersion;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Venue;
use App\Support\Signing\HmacSigner;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;

/**
 * Builds a complete, sellable tenant: venue, published map, priced event, connected storefront.
 *
 * Tests that care about one behaviour should not each re-derive the twelve rows needed before a
 * seat can be sold.
 */
trait BuildsSeatingFixtures
{
    protected function makeTenant(string $name = 'Acme Events'): Tenant
    {
        $tenant = Tenant::factory()->create(['name' => $name]);
        $plan = Plan::factory()->create();

        app(TenantContext::class)->runAs($tenant, fn () => Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'current_period_start' => now()->startOfMonth(),
            'current_period_end' => now()->endOfMonth(),
        ]));

        return $tenant;
    }

    protected function makeUser(Tenant $tenant, string $role = 'owner'): User
    {
        $user = User::factory()->create();

        app(TenantContext::class)->runAs($tenant, fn () => TenantUser::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => $role,
        ]));

        return $user;
    }

    /**
     * A rectangular block of seats: one section, `$rows` rows of `$perRow` seats, all in zone
     * "standard".
     */
    protected function geometry(int $rows = 3, int $perRow = 5, string $sectionKey = 'stalls'): array
    {
        $rowList = [];

        for ($r = 0; $r < $rows; $r++) {
            $seats = [];
            $rowName = chr(ord('A') + $r);

            for ($s = 1; $s <= $perRow; $s++) {
                $seats[] = [
                    'key' => $rowName.$s,
                    'label' => (string) $s,
                    'x' => 100 + ($s * 30),
                    'y' => 100 + ($r * 30),
                    'shape' => 'circle',
                    'zone_key' => 'standard',
                    'accessible' => false,
                ];
            }

            $rowList[] = ['key' => $rowName, 'name' => 'Row '.$rowName, 'seats' => $seats];
        }

        return [
            'canvas' => ['width' => 1200, 'height' => 800],
            'sections' => [[
                'key' => $sectionKey,
                'name' => 'Stalls',
                'color' => '#3366ff',
                'rows' => $rowList,
            ]],
            'shapes' => [['kind' => 'stage', 'x' => 100, 'y' => 40, 'width' => 300, 'height' => 40, 'label' => 'Stage']],
            'texts' => [],
        ];
    }

    /**
     * @return array{tenant: Tenant, event: Event, seats: \Illuminate\Support\Collection}
     */
    protected function makeSellableEvent(
        ?Tenant $tenant = null,
        int $rows = 3,
        int $perRow = 5,
        int $amount = 2500,
    ): array {
        $tenant ??= $this->makeTenant();

        return app(TenantContext::class)->runAs($tenant, function () use ($tenant, $rows, $perRow, $amount) {
            $venue = Venue::factory()->create(['tenant_id' => $tenant->id]);

            $map = SeatMap::create([
                'venue_id' => $venue->id,
                'name' => 'Main hall',
            ]);

            $version = SeatMapVersion::create([
                'seat_map_id' => $map->id,
                'version' => 1,
                'status' => 'draft',
                'geometry' => $this->geometry($rows, $perRow),
            ]);

            app(SeatMapPublisher::class)->publish($map, $version);
            $map->refresh();

            $event = Event::create([
                'venue_id' => $venue->id,
                'seat_map_id' => $map->id,
                'seat_map_version_id' => $map->published_version_id,
                'public_id' => 'evt_'.Str::lower(Str::random(20)),
                'name' => 'Opening night',
                'status' => 'published',
                'starts_at' => now()->addWeek(),
                'timezone' => 'Europe/Berlin',
                'currency' => 'EUR',
            ]);

            EventPriceZone::create([
                'event_id' => $event->id,
                'key' => 'standard',
                'name' => 'Standard',
                'amount' => $amount,
            ]);

            return [
                'tenant' => $tenant,
                'venue' => $venue,
                'map' => $map,
                'event' => $event,
                'seats' => \App\Models\Seat::where('seat_map_id', $map->id)->orderBy('key')->get(),
            ];
        });
    }

    /** @return array{client: ApiClient, key_id: string, secret: string} */
    protected function makeApiClient(Tenant $tenant): array
    {
        return app(TenantContext::class)->runAs($tenant, function () use ($tenant) {
            $client = ApiClient::factory()->create(['tenant_id' => $tenant->id]);
            $issued = ApiKey::issue($client, 'test');

            return [
                'client' => $client,
                'key_id' => $issued['model']->key_id,
                'secret' => $issued['secret'],
            ];
        });
    }

    /** Headers for a signed server-to-server call. */
    protected function signedHeaders(string $keyId, string $secret, string $method, string $path, string $body = ''): array
    {
        $timestamp = (string) time();
        $nonce = Str::random(24);

        return [
            'X-Seatmap-Key' => $keyId,
            'X-Seatmap-Timestamp' => $timestamp,
            'X-Seatmap-Nonce' => $nonce,
            'X-Seatmap-Signature' => HmacSigner::sign(
                $secret,
                HmacSigner::canonicalString($method, $path, $timestamp, $nonce, $body),
            ),
        ];
    }
}
