<?php

namespace Database\Seeders;

use App\Domain\SeatMaps\SeatMapPublisher;
use App\Models\ApiClient;
use App\Models\ApiKey;
use App\Models\CheckinDevice;
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
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A working demo: two tenants, so cross-tenant isolation is visible immediately, each with a
 * published map, a priced event and a connected storefront.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $plans = $this->seedPlans();

        $primary = $this->seedTenant(
            'Northgate Theatre', 'northgate', 'owner@northgate.test', $plans['pro'], seatsPerRow: 18, rows: 12
        );

        $secondary = $this->seedTenant(
            'Riverside Arena', 'riverside', 'owner@riverside.test', $plans['starter'], seatsPerRow: 10, rows: 6
        );

        $this->command?->newLine();
        $this->command?->info('Demo data ready.');
        $this->command?->table(
            ['Tenant', 'Login', 'Password', 'Event public id', 'API key id', 'API secret'],
            [
                [$primary['tenant']->name, $primary['email'], 'password', $primary['event']->public_id, $primary['key_id'], $primary['secret']],
                [$secondary['tenant']->name, $secondary['email'], 'password', $secondary['event']->public_id, $secondary['key_id'], $secondary['secret']],
            ],
        );
        $this->command?->warn('API secrets are shown here only because this is seed data. Real secrets are displayed once, at creation, and never again.');
    }

    /** @return array<string, Plan> */
    private function seedPlans(): array
    {
        return [
            'starter' => Plan::firstOrCreate(['key' => 'starter'], [
                'name' => 'Starter',
                'price_amount' => 4900,
                'currency' => 'EUR',
                'interval' => 'month',
                'limits' => [
                    'max_venues' => 2, 'max_events' => 20,
                    'max_seats_per_map' => 2000, 'max_scans_per_month' => 20000,
                ],
            ]),
            'pro' => Plan::firstOrCreate(['key' => 'pro'], [
                'name' => 'Professional',
                'price_amount' => 14900,
                'currency' => 'EUR',
                'interval' => 'month',
                'limits' => [
                    'max_venues' => 25, 'max_events' => 500,
                    'max_seats_per_map' => 25000, 'max_scans_per_month' => 500000,
                ],
            ]),
        ];
    }

    private function seedTenant(
        string $name,
        string $slug,
        string $email,
        Plan $plan,
        int $seatsPerRow,
        int $rows,
    ): array {
        $tenant = Tenant::firstOrCreate(['slug' => $slug], [
            'name' => $name,
            'status' => 'active',
            'timezone' => 'Europe/Berlin',
            'locale' => 'en',
        ]);

        $user = User::firstOrCreate(['email' => $email], [
            'name' => explode('@', $email)[0],
            'password' => Hash::make('password'),
        ]);

        return app(TenantContext::class)->runAs($tenant, function () use ($tenant, $user, $plan, $seatsPerRow, $rows, $email, $name) {
            TenantUser::firstOrCreate(
                ['tenant_id' => $tenant->id, 'user_id' => $user->id],
                ['role' => 'owner'],
            );

            Subscription::firstOrCreate(
                ['tenant_id' => $tenant->id, 'plan_id' => $plan->id],
                [
                    'status' => 'active',
                    'current_period_start' => now()->startOfMonth(),
                    'current_period_end' => now()->endOfMonth(),
                ],
            );

            $venue = Venue::firstOrCreate(
                ['tenant_id' => $tenant->id, 'name' => $name],
                ['city' => 'Berlin', 'country' => 'DE', 'timezone' => 'Europe/Berlin'],
            );

            $map = SeatMap::firstOrCreate(
                ['tenant_id' => $tenant->id, 'venue_id' => $venue->id, 'name' => 'Main auditorium'],
                ['description' => 'Stalls and balcony'],
            );

            if (! $map->published_version_id) {
                $version = SeatMapVersion::create([
                    'seat_map_id' => $map->id,
                    'version' => 1,
                    'status' => 'draft',
                    'geometry' => $this->auditorium($rows, $seatsPerRow),
                ]);

                app(SeatMapPublisher::class)->publish($map, $version);
                $map->refresh();
            }

            $event = Event::firstOrCreate(
                ['tenant_id' => $tenant->id, 'seat_map_id' => $map->id, 'name' => 'Opening night'],
                [
                    'venue_id' => $venue->id,
                    'seat_map_version_id' => $map->published_version_id,
                    'public_id' => 'evt_'.Str::lower(Str::random(20)),
                    'status' => 'published',
                    'starts_at' => now()->addWeeks(3)->setTime(19, 30),
                    'ends_at' => now()->addWeeks(3)->setTime(22, 0),
                    'timezone' => 'Europe/Berlin',
                    'currency' => 'EUR',
                ],
            );

            // One price zone per chart category, so every bookable object is priced the moment the
            // event is created.
            foreach ([
                ['premium', 'Premium', 6500, '#b8860b'],
                ['standard', 'Standard', 3500, '#2d6cdf'],
                ['balcony', 'Balcony', 1900, '#3f9c6d'],
                ['standing', 'Standing', 2200, '#e0526a'],
                ['accessible', 'Wheelchair space', 3500, '#7b5ea7'],
            ] as [$key, $zoneName, $amount, $color]) {
                EventPriceZone::firstOrCreate(
                    ['event_id' => $event->id, 'key' => $key],
                    ['name' => $zoneName, 'amount' => $amount, 'color' => $color],
                );
            }

            $client = ApiClient::firstOrCreate(
                ['tenant_id' => $tenant->id, 'name' => $name.' website'],
                ['site_url' => "https://{$tenant->slug}.test", 'allowed_origins' => ["https://{$tenant->slug}.test"], 'status' => 'active'],
            );

            $issued = ApiKey::issue($client, 'seeded');

            CheckinDevice::firstOrCreate(
                ['tenant_id' => $tenant->id, 'name' => 'Front door scanner'],
                [
                    'status' => 'pending',
                    'pairing_code_hash' => hash('sha256', $tenant->slug.'-pair'),
                    'pairing_expires_at' => now()->addDays(7),
                ],
            )->grantAccessTo($event);

            return [
                'tenant' => $tenant,
                'event' => $event,
                'email' => $email,
                'key_id' => $issued['model']->key_id,
                'secret' => $issued['secret'],
            ];
        });
    }

    /**
     * A chart with the shapes a real venue has: a curved stalls block inside a section, a straight
     * balcony, a standing pit sold by quantity, cabaret tables sold whole, a stage, and the icons
     * that tell people where the doors are.
     *
     * Rows carry an anchor, rotation, curve and spacing — seat positions follow from those.
     */
    private function auditorium(int $rows, int $seatsPerRow): array
    {
        $categories = [
            ['key' => 'premium', 'label' => 'Premium', 'color' => '#b8860b', 'accessible' => false],
            ['key' => 'standard', 'label' => 'Standard', 'color' => '#2d6cdf', 'accessible' => false],
            ['key' => 'balcony', 'label' => 'Balcony', 'color' => '#3f9c6d', 'accessible' => false],
            ['key' => 'standing', 'label' => 'Standing', 'color' => '#e0526a', 'accessible' => false],
            ['key' => 'accessible', 'label' => 'Wheelchair space', 'color' => '#7b5ea7', 'accessible' => true],
        ];

        $stalls = $this->section('stalls', 'Stalls', [[300, 300], [900, 300], [960, 700], [240, 700]]);

        for ($r = 0; $r < $rows; $r++) {
            $stalls['objects'][] = $this->row(
                key: 'stalls-'.$r,
                label: $this->rowLetter($r),
                seats: $seatsPerRow,
                x: 600,
                y: 340 + $r * 30,
                // The front rows curve towards the stage and flatten as they go back, which is what
                // a real auditorium does.
                curve: max(0, 18 - $r * 1.5),
                category: $r < 3 ? 'premium' : 'standard',
                accessibleEnds: $r === 0,
            );
        }

        $balcony = $this->section('balcony', 'Balcony', [[320, 760], [880, 760], [880, 900], [320, 900]]);
        $balconyRows = max(2, intdiv($rows, 3));

        for ($r = 0; $r < $balconyRows; $r++) {
            $balcony['objects'][] = $this->row(
                key: 'balcony-'.$r,
                label: $this->rowLetter($r),
                seats: max(4, $seatsPerRow - 4),
                x: 600,
                y: 800 + $r * 30,
                curve: 0,
                category: 'balcony',
            );
        }

        return [
            'version' => 2,
            'name' => 'Main auditorium',
            // The middle of the stage: what "best available" sorts towards.
            'focalPoint' => ['x' => 600, 'y' => 170],
            'categories' => $categories,
            'floors' => [[
                'key' => '1',
                'name' => 'Level 1',
                'canvas' => ['width' => 1200, 'height' => 1000, 'background' => null],
                'objects' => [
                    [
                        'type' => 'shape', 'key' => 'stage', 'layer' => 'background', 'kind' => 'stage',
                        'x' => 420, 'y' => 120, 'width' => 360, 'height' => 70, 'rotation' => 0,
                        'cornerRadius' => 6, 'points' => null, 'fill' => null, 'label' => 'Stage',
                    ],
                    $stalls,
                    $balcony,
                    [
                        'type' => 'area', 'key' => 'pit', 'layer' => 'interactive',
                        'shape' => [
                            'kind' => 'rect', 'x' => 300, 'y' => 210, 'width' => 600, 'height' => 70,
                            'rotation' => 0, 'cornerRadius' => 24, 'points' => null,
                        ],
                        'translucent' => false, 'scale' => 1, 'categoryKey' => 'standing', 'entrance' => 'Door A',
                        'labeling' => [
                            'label' => 'Standing pit', 'displayedLabel' => null, 'visible' => true,
                            'fontSize' => 22, 'positionX' => 0, 'positionY' => 0, 'locked' => false,
                        ],
                        'capacity' => ['type' => 'generalAdmission', 'places' => 250],
                    ],
                    $this->table('cabaret-1', 'Table 1', 180, 420),
                    $this->table('cabaret-2', 'Table 2', 180, 560),
                    $this->table('cabaret-3', 'Table 3', 1020, 420),
                    $this->table('cabaret-4', 'Table 4', 1020, 560),
                    ['type' => 'icon', 'key' => 'icon-entrance', 'layer' => 'foreground', 'name' => 'entrance', 'x' => 180, 'y' => 940, 'size' => 24, 'rotation' => 0],
                    ['type' => 'icon', 'key' => 'icon-exit', 'layer' => 'foreground', 'name' => 'exit', 'x' => 1020, 'y' => 940, 'size' => 24, 'rotation' => 0],
                    ['type' => 'icon', 'key' => 'icon-bar', 'layer' => 'foreground', 'name' => 'bar', 'x' => 600, 'y' => 950, 'size' => 24, 'rotation' => 0],
                    [
                        'type' => 'text', 'key' => 'text-title', 'layer' => 'foreground',
                        'text' => 'Main auditorium', 'x' => 520, 'y' => 80,
                        'fontSize' => 24, 'color' => null, 'rotation' => 0,
                    ],
                ],
            ]],
        ];
    }

    private function section(string $key, string $label, array $polygon): array
    {
        return [
            'type' => 'section', 'key' => $key, 'layer' => 'interactive', 'label' => $label,
            'labeling' => ['label' => $label, 'displayedLabel' => null, 'visible' => true, 'fontSize' => 18, 'locked' => false],
            'polygon' => $polygon, 'categoryKey' => null, 'color' => null, 'entrance' => null,
            'objects' => [],
        ];
    }

    private function row(
        string $key,
        string $label,
        int $seats,
        float $x,
        float $y,
        float $curve,
        string $category,
        bool $accessibleEnds = false,
    ): array {
        $list = [];

        for ($i = 1; $i <= $seats; $i++) {
            $list[] = [
                'type' => 'seat',
                'key' => $key.'-'.$i,
                'label' => (string) $i,
                // Wheelchair spaces at the ends of the front row, where they belong.
                'categoryKey' => $accessibleEnds && ($i === 1 || $i === $seats) ? 'accessible' : null,
                'accessible' => $accessibleEnds && ($i === 1 || $i === $seats),
                'entrance' => null,
            ];
        }

        return [
            'type' => 'row', 'key' => 'row-'.$key, 'layer' => 'interactive',
            'x' => $x, 'y' => $y, 'rotation' => 0, 'curve' => $curve, 'seatSpacing' => 4,
            'categoryKey' => $category, 'entrance' => null,
            'labeling' => [
                'enabled' => true, 'label' => $label, 'displayedLabel' => null,
                'position' => 'both', 'displayedType' => 'Row', 'locked' => false,
            ],
            'seatLabeling' => ['scheme' => 'numeric', 'displayedType' => 'Seat', 'locked' => false],
            'seats' => $list,
        ];
    }

    /** A cabaret table: eight chairs, sold as one booking. */
    private function table(string $key, string $label, float $x, float $y): array
    {
        $seats = [];

        for ($i = 1; $i <= 8; $i++) {
            $seats[] = ['type' => 'seat', 'key' => $key.'-'.$i, 'label' => (string) $i, 'categoryKey' => null, 'accessible' => false, 'entrance' => null];
        }

        return [
            'type' => 'table', 'key' => $key, 'layer' => 'interactive', 'label' => $label,
            'labeling' => ['label' => $label, 'displayedLabel' => null, 'visible' => true, 'fontSize' => 13, 'locked' => false],
            'shape' => 'round', 'x' => $x, 'y' => $y, 'width' => 90, 'height' => 90, 'rotation' => 0,
            'bookAs' => 'table', 'categoryKey' => 'premium', 'entrance' => null,
            'seatLabeling' => ['scheme' => 'numeric', 'displayedType' => 'Seat', 'locked' => false],
            'seats' => $seats,
        ];
    }

    private function rowLetter(int $index): string
    {
        $name = '';
        $index += 1;

        while ($index > 0) {
            $name = chr(65 + (($index - 1) % 26)).$name;
            $index = intdiv($index - 1, 26);
        }

        return $name;
    }
}
