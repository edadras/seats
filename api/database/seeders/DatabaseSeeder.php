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

            foreach ([
                ['premium', 'Premium', 6500, '#b8860b'],
                ['standard', 'Standard', 3500, '#2d6cdf'],
                ['balcony', 'Balcony', 1900, '#3f9c6d'],
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
     * A stalls block plus a smaller balcony, priced by zone, with a stage and two aisles — enough
     * shape to exercise the editor and the widget rather than a bare grid.
     */
    private function auditorium(int $rows, int $seatsPerRow): array
    {
        $sections = [];

        $sections[] = [
            'key' => 'stalls',
            'name' => 'Stalls',
            'color' => '#2d6cdf',
            'rows' => $this->rowsFor($rows, $seatsPerRow, startY: 220, zoneFor: function (int $rowIndex) {
                return $rowIndex < 3 ? 'premium' : 'standard';
            }),
        ];

        $balconyRows = max(2, intdiv($rows, 3));

        $sections[] = [
            'key' => 'balcony',
            'name' => 'Balcony',
            'color' => '#3f9c6d',
            'rows' => $this->rowsFor($balconyRows, max(4, $seatsPerRow - 4), startY: 220 + ($rows * 34) + 80, zoneFor: fn () => 'balcony'),
        ];

        return [
            'canvas' => ['width' => 1200, 'height' => 900, 'background' => null],
            'sections' => $sections,
            'shapes' => [
                ['kind' => 'stage', 'x' => 380, 'y' => 80, 'width' => 440, 'height' => 70, 'label' => 'Stage'],
                ['kind' => 'entrance', 'x' => 120, 'y' => 820, 'width' => 90, 'height' => 30, 'label' => 'Entrance'],
                ['kind' => 'exit', 'x' => 990, 'y' => 820, 'width' => 90, 'height' => 30, 'label' => 'Exit'],
            ],
            'texts' => [
                ['text' => 'Main auditorium', 'x' => 520, 'y' => 40, 'size' => 20],
            ],
        ];
    }

    private function rowsFor(int $rows, int $seatsPerRow, int $startY, callable $zoneFor): array
    {
        $list = [];

        for ($r = 0; $r < $rows; $r++) {
            $rowName = chr(ord('A') + $r);
            $seats = [];

            for ($s = 1; $s <= $seatsPerRow; $s++) {
                // A gap in the middle stands in for the central aisle.
                $aisleOffset = $s > intdiv($seatsPerRow, 2) ? 40 : 0;

                $seats[] = [
                    'key' => $rowName.'-'.$s,
                    'label' => (string) $s,
                    'x' => 300 + ($s * 34) + $aisleOffset,
                    'y' => $startY + ($r * 34),
                    'shape' => 'chair',
                    'zone_key' => $zoneFor($r),
                    'accessible' => $r === 0 && $s <= 2,
                ];
            }

            $list[] = ['key' => $rowName, 'name' => 'Row '.$rowName, 'seats' => $seats];
        }

        return $list;
    }
}
