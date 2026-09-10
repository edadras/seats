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
     * Make the next request as this member.
     *
     * The token alone is not enough inside a test: Sanctum's guard is resolved once and keeps the
     * user it found, so a second call with a different bearer would still be answered as the first
     * caller — and a test that expects a refusal would quietly pass as somebody allowed. Forgetting
     * the guards makes the switch real, the way a second HTTP request would.
     */
    protected function asMember(User $user, string $token = 'test'): static
    {
        app('auth')->forgetGuards();

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$user->createToken($token)->plainTextToken,
        ]);
    }

    /**
     * A v2 chart: one section holding `$rows` rows of `$perRow` seats, all in the "standard"
     * category, plus a stage.
     *
     * Rows carry an anchor, rotation, curve and spacing rather than per-seat coordinates — seat
     * positions are computed from those, on both the client and the server.
     */
    protected function geometry(
        int $rows = 3,
        int $perRow = 5,
        string $sectionKey = 'stalls',
        bool $accessiblePair = false,
    ): array
    {
        $rowObjects = [];

        for ($r = 0; $r < $rows; $r++) {
            $rowName = chr(ord('A') + $r);
            $seats = [];

            for ($s = 1; $s <= $perRow; $s++) {
                $seats[] = [
                    'type' => 'seat',
                    'key' => $sectionKey.'-'.$rowName.'-'.$s,
                    'label' => (string) $s,
                    'categoryKey' => null,
                    // Off unless a test asks: the last row then ends in a wheelchair space with
                    // the chair beside it. Every other fixture stays a plain grid, because a
                    // fixture that quietly contains a pair changes what "three together" means.
                    'accessible' => $accessiblePair && $r === $rows - 1 && $s === $perRow,
                    'companion' => $accessiblePair && $r === $rows - 1 && $s === $perRow - 1,
                    'entrance' => null,
                ];
            }

            $rowObjects[] = [
                'type' => 'row',
                'key' => $sectionKey.'-row-'.$rowName,
                'layer' => 'interactive',
                'x' => 400,
                'y' => 300 + ($r * 34),
                'rotation' => 0,
                'curve' => 0,
                'seatSpacing' => 4,
                'categoryKey' => 'standard',
                'entrance' => null,
                'labeling' => [
                    'enabled' => true,
                    'label' => $rowName,
                    'displayedLabel' => null,
                    'position' => 'both',
                    'displayedType' => 'Row',
                    'locked' => false,
                ],
                'seatLabeling' => ['scheme' => 'numeric', 'displayedType' => 'Seat', 'locked' => false],
                'seats' => $seats,
            ];
        }

        return [
            'version' => 2,
            'name' => 'Test chart',
            'focalPoint' => ['x' => 400, 'y' => 100],
            'categories' => [
                ['key' => 'standard', 'label' => 'Standard', 'color' => '#2d6cdf', 'accessible' => false],
            ],
            'floors' => [[
                'key' => '1',
                'name' => 'Level 1',
                'canvas' => ['width' => 1200, 'height' => 900, 'background' => null],
                'objects' => [
                    [
                        'type' => 'section',
                        'key' => $sectionKey,
                        'layer' => 'interactive',
                        'label' => 'Stalls',
                        'labeling' => ['label' => 'Stalls', 'displayedLabel' => null, 'visible' => true, 'fontSize' => 16, 'locked' => false],
                        'polygon' => [[300, 260], [900, 260], [900, 700], [300, 700]],
                        'categoryKey' => null,
                        'color' => '#3366ff',
                        'entrance' => null,
                        'objects' => $rowObjects,
                    ],
                    [
                        'type' => 'shape',
                        'key' => 'stage',
                        'layer' => 'background',
                        'kind' => 'stage',
                        'x' => 450, 'y' => 120, 'width' => 300, 'height' => 50,
                        'rotation' => 0, 'cornerRadius' => 4, 'points' => null,
                        'fill' => null, 'label' => 'Stage',
                    ],
                ],
            ]],
        ];
    }

    /**
     * The same chart with a general admission area added — standing room sold by quantity rather
     * than by seat.
     */
    protected function geometryWithStandingArea(int $places = 100, int $rows = 3, int $perRow = 5): array
    {
        $chart = $this->geometry($rows, $perRow);

        $chart['categories'][] = ['key' => 'standing', 'label' => 'Standing', 'color' => '#e0526a', 'accessible' => false];

        $chart['floors'][0]['objects'][] = [
            'type' => 'area',
            'key' => 'pit',
            'layer' => 'interactive',
            'shape' => [
                'kind' => 'rect',
                'x' => 340, 'y' => 740, 'width' => 520, 'height' => 120,
                'rotation' => 0, 'cornerRadius' => 12, 'points' => null,
            ],
            'translucent' => false,
            'scale' => 1,
            'categoryKey' => 'standing',
            'entrance' => null,
            'labeling' => [
                'label' => 'Standing pit',
                'displayedLabel' => null,
                'visible' => true,
                'fontSize' => 20,
                'positionX' => 0,
                'positionY' => 0,
                'locked' => false,
            ],
            'capacity' => ['type' => 'generalAdmission', 'places' => $places],
        ];

        return $chart;
    }

    /**
     * @return array{tenant: Tenant, event: Event, seats: \Illuminate\Support\Collection}
     */
    protected function makeSellableEvent(
        ?Tenant $tenant = null,
        int $rows = 3,
        int $perRow = 5,
        int $amount = 2500,
        ?array $chart = null,
    ): array {
        $tenant ??= $this->makeTenant();

        return app(TenantContext::class)->runAs($tenant, function () use ($tenant, $rows, $perRow, $amount, $chart) {
            $venue = Venue::factory()->create(['tenant_id' => $tenant->id]);

            $map = SeatMap::create([
                'venue_id' => $venue->id,
                'name' => 'Main hall',
            ]);

            $version = SeatMapVersion::create([
                'seat_map_id' => $map->id,
                'version' => 1,
                'status' => 'draft',
                'geometry' => $chart ?? $this->geometry($rows, $perRow),
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

            // Priced even when the chart has no standing area, so a fixture that adds one later
            // does not have to remember to price it.
            EventPriceZone::create([
                'event_id' => $event->id,
                'key' => 'standing',
                'name' => 'Standing',
                'amount' => (int) round($amount / 2),
            ]);

            return [
                'tenant' => $tenant,
                'venue' => $venue,
                'map' => $map,
                // Re-read: a model straight out of create() carries only what was written, so the
                // column defaults — the per-order seat cap among them — are still null on it, and
                // a test that holds two seats is refused for holding more than nought.
                'event' => $event->fresh(),
                'seats' => \App\Models\Seat::where('seat_map_id', $map->id)->orderBy('key')->get(),
                'areas' => \App\Models\CapacityObject::where('seat_map_id', $map->id)->orderBy('key')->get(),
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
    /**
     * The same headers, in the shape Laravel's `call()` wants.
     *
     * Here rather than in one test, because every test that registers an order server-to-server
     * needs it, and a second copy is a second thing to keep in step with the signer.
     */
    protected function serverHeaders(array $headers): array
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $server;
    }

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
