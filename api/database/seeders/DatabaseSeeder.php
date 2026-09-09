<?php

namespace Database\Seeders;

use App\Domain\SeatMaps\SeatMapPublisher;
use App\Domain\Sites\SiteProvisioner;
use App\Models\ApiClient;
use App\Models\ApiKey;
use App\Models\CheckinDevice;
use App\Domain\Checkin\CheckinService;
use App\Domain\Inventory\HoldService;
use App\Domain\Orders\OrderService;
use App\Models\Event;
use App\Models\ExternalOrder;
use App\Models\Seat;
use App\Models\EventPriceZone;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\SeatMap;
use App\Models\SeatMapVersion;
use App\Models\SiteDomain;
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

        $operator = $this->seedPlatformOperator();

        $this->command?->newLine();
        $this->command?->info('Demo data ready.');
        $this->command?->line('Platform console: /console — '.$operator.' / password');
        $this->command?->table(
            ['Tenant', 'Login', 'Password', 'Event public id', 'API key id', 'API secret'],
            [
                [$primary['tenant']->name, $primary['email'], 'password', $primary['event']->public_id, $primary['key_id'], $primary['secret']],
                [$secondary['tenant']->name, $secondary['email'], 'password', $secondary['event']->public_id, $secondary['key_id'], $secondary['secret']],
            ],
        );
        $this->command?->warn('API secrets are shown here only because this is seed data. Real secrets are displayed once, at creation, and never again.');
    }

    /**
     * Somebody who runs the platform, so the console is reachable in a demo.
     *
     * Deliberately not a member of either tenant: an operator is not a member of anybody's
     * account, and a seeder that blurred that would teach the wrong thing about the boundary.
     */
    private function seedPlatformOperator(): string
    {
        $email = 'operator@seatmap.test';

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => 'Platform operator',
                'password' => Hash::make('password'),
                'locale' => 'en',
                'email_verified_at' => now(),
            ],
        );

        PlatformAdmin::firstOrCreate(['user_id' => $user->id], ['level' => 'operator']);

        return $email;
    }

    /** @return array<string, Plan> */
    private function seedPlans(): array
    {
        return [
            'starter' => Plan::firstOrCreate(['key' => 'starter'], [
                'name' => 'Starter',
                'price_amount' => 4900,
                // Basis points: 500 is 5% of what an organiser keeps. A smaller plan pays a
                // larger share, which is how ticketing is actually priced.
                'commission_rate' => 500,
                'currency' => 'EUR',
                'interval' => 'month',
                'limits' => [
                    'max_venues' => 2, 'max_events' => 20, 'max_seats_per_map' => 2000,
                ],
            ]),
            'pro' => Plan::firstOrCreate(['key' => 'pro'], [
                'name' => 'Professional',
                'price_amount' => 14900,
                'commission_rate' => 250,
                'currency' => 'EUR',
                'interval' => 'month',
                'limits' => [
                    'max_venues' => 25, 'max_events' => 500, 'max_seats_per_map' => 25000,
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

        return app(TenantContext::class)->runAs($tenant, function () use ($tenant, $user, $plan, $seatsPerRow, $rows, $email, $name, $slug) {
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
                    'category' => 'Theatre',
                    // Written in the venue's own clock and converted on the way in, because
                    // that is what an organiser means by "half past seven" — and because Eloquent
                    // stores the wall time it is handed and forgets the offset, so a Berlin-zoned
                    // Carbon saved as-is comes back two hours late.
                    'starts_at' => now('Europe/Berlin')->addWeeks(3)->setTime(19, 30)->utc(),
                    'ends_at' => now('Europe/Berlin')->addWeeks(3)->setTime(22, 0)->utc(),
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

            /*
             * A second night, sold without a single named seat.
             *
             * Half the events on this platform are like this — a warehouse, a festival tent, a
             * standing gig — and a demo that only ever shows a theatre hides every way that path
             * differs: no plan to zoom into, a quantity rather than a chair, and a capacity per
             * area that is the only thing standing between a sale and a fire risk.
             */
            $standingMap = SeatMap::firstOrCreate(
                ['tenant_id' => $tenant->id, 'venue_id' => $venue->id, 'name' => 'The warehouse'],
                ['description' => 'Standing room, sold by the head'],
            );

            if (! $standingMap->published_version_id) {
                app(SeatMapPublisher::class)->publish($standingMap, SeatMapVersion::create([
                    'seat_map_id' => $standingMap->id,
                    'version' => 1,
                    'status' => 'draft',
                    'geometry' => $this->warehouse(),
                ]));

                $standingMap->refresh();
            }

            $standingEvent = Event::firstOrCreate(
                ['tenant_id' => $tenant->id, 'seat_map_id' => $standingMap->id, 'name' => 'Late night session'],
                [
                    'venue_id' => $venue->id,
                    'seat_map_version_id' => $standingMap->published_version_id,
                    'public_id' => 'evt_'.Str::lower(Str::random(20)),
                    'status' => 'published',
                    'category' => 'Club',
                    'description' => 'Doors at ten, four rooms, one ticket. No seats — come and stand.',
                    'starts_at' => now('Europe/Berlin')->addWeeks(5)->setTime(22, 0)->utc(),
                    'ends_at' => now('Europe/Berlin')->addWeeks(5)->setTime(22, 0)->addHours(5)->utc(),
                    'timezone' => 'Europe/Berlin',
                    'currency' => 'EUR',
                    'max_seats_per_order' => 8,
                ],
            );

            foreach ([
                ['floor', 'Floor', 2400, '#e0526a'],
                ['gallery', 'Gallery', 3600, '#b8860b'],
                ['terrace', 'Terrace', 3000, '#3f9c6d'],
                ['accessible', 'Wheelchair space', 2400, '#7b5ea7'],
            ] as [$key, $zoneName, $amount, $color]) {
                EventPriceZone::firstOrCreate(
                    ['event_id' => $standingEvent->id, 'key' => $key],
                    ['name' => $zoneName, 'amount' => $amount, 'color' => $color],
                );
            }

            $client = ApiClient::firstOrCreate(
                ['tenant_id' => $tenant->id, 'name' => $name.' website'],
                ['site_url' => "https://{$tenant->slug}.test", 'allowed_origins' => ["https://{$tenant->slug}.test"], 'status' => 'active'],
            );

            $issued = ApiKey::issue($client, 'seeded');

            $scanner = CheckinDevice::firstOrCreate(
                ['tenant_id' => $tenant->id, 'name' => 'Front door scanner'],
                [
                    'status' => 'pending',
                    'pairing_code_hash' => hash('sha256', $tenant->slug.'-pair'),
                    'pairing_expires_at' => now()->addDays(7),
                ],
            );

            $scanner->grantAccessTo($event);
            $scanner->grantAccessTo($standingEvent);

            // A hosted site, on a hostname that resolves without DNS: 127.0.0.1.nip.io and
            // localhost both point at this machine, so the site is reachable the moment it is
            // seeded rather than after a hosts-file edit.
            $site = app(SiteProvisioner::class)->create($name, [
                'timezone' => $tenant->timezone,
                'currency' => 'EUR',
                'theme_key' => 'northgate' === $slug ? 'playbill' : 'noir',
                'brand' => ['tagline' => 'Tickets straight from the box office.'],
            ]);

            SiteDomain::firstOrCreate(
                ['hostname' => $slug.'.localhost'],
                [
                    'tenant_id' => $tenant->id,
                    'site_id' => $site->id,
                    'is_primary' => true,
                    'verification_token' => SiteDomain::newToken(),
                    'verified_at' => now(),
                ],
            );

            $site->update(['status' => 'live']);

            $this->seedSales($tenant, $event, $client);

            return [
                'tenant' => $tenant,
                'event' => $event,
                'site' => $site,
                'email' => $email,
                'key_id' => $issued['model']->key_id,
                'secret' => $issued['secret'],
            ];
        });
    }

    /**
     * A fortnight of sales, so the reports screen has something to say.
     *
     * Made through the real services — a hold, an order, a confirmation, a scan at the door —
     * rather than by writing rows. Seeded numbers that could not have been produced by the
     * software are the numbers that hide the bug where the software cannot produce them.
     */
    private function seedSales(Tenant $tenant, Event $event, ApiClient $client): void
    {
        if (ExternalOrder::where('event_id', $event->id)->exists()) {
            return; // Re-seeding an existing demo should not double its takings.
        }

        // Read back from the database first: the model that `firstOrCreate` handed back does not
        // carry the columns the database defaulted, and one of them is how many seats a hold may
        // take — which is nought if you believe the in-memory copy.
        $event = $event->fresh();

        $seats = Seat::where('seat_map_id', $event->seat_map_id)->orderBy('key')->get();
        $holds = app(HoldService::class);
        $orders = app(OrderService::class);
        $checkins = app(CheckinService::class);
        $device = CheckinDevice::where('tenant_id', $tenant->id)->first();

        $buyers = [
            ['name' => 'Dana Scully', 'email' => 'dana@example.test'],
            ['name' => 'Amir Rahimi', 'email' => 'amir@example.test'],
            ['name' => 'Lotte Weber', 'email' => 'lotte@example.test'],
            ['name' => 'Ines Rossi', 'email' => 'ines@example.test'],
            ['name' => 'Karim Haddad', 'email' => 'karim@example.test'],
            ['name' => 'Sofie Jansen', 'email' => 'sofie@example.test'],
        ];

        // Spread through the house rather than taken off the front of the list: six parties who
        // all sat in the balcony make a demo where every report has one bar.
        $stride = max(1, intdiv($seats->count(), count($buyers) * 4));

        foreach ($buyers as $index => $buyer) {
            $size = 1 + ($index % 3);
            $chosen = $seats->slice($index * $stride * 3, $size)->pluck('id')->all();

            if (count($chosen) < $size) {
                break;
            }

            // Spread backwards through the last fortnight so a report grouped by day has days.
            $when = now()->subDays(13 - $index * 2)->setTime(10 + $index, 15);

            $hold = $holds->create($event, $chosen, 'seed-'.$index, $client->id, '127.0.0.1');

            [$order] = $orders->register($client, 'seed-'.$tenant->slug.'-'.$index, $hold->token, $buyer);
            $confirmed = $orders->confirm($order, $buyer, $when);

            ExternalOrder::whereKey($order->id)->update(['created_at' => $when, 'updated_at' => $when]);

            // The first two parties turned up and were scanned in; the rest have not arrived yet.
            // The plaintext token exists only on the models this call just minted, which is the
            // whole point of it — so the scan has to happen here or not at all.
            if ($index < 2) {
                foreach ($confirmed->allocations as $allocation) {
                    $token = $allocation->ticket?->plainToken;

                    if ($token) {
                        $checkins->scan(
                            $event,
                            $token,
                            $device,
                            $event->starts_at->copy()->subMinutes(35 - $index * 5),
                        );
                    }
                }
            }
        }
    }

    /**
     * A chart with the shapes a real venue has: a curved stalls block inside a section, a straight
     * balcony, a standing pit sold by quantity, cabaret tables sold whole, a stage, and the icons
     * that tell people where the doors are.
     *
     * Rows carry an anchor, rotation, curve and spacing — seat positions follow from those.
     */
    /**
     * A room with no chairs in it.
     *
     * Four areas, each with a capacity and a price of its own, and not one seat. Everything the
     * picker does with a plan — zoom into a section, click a chair, name a row — has to have a
     * sensible answer here too, and the only way to keep that true is to have such a room in the
     * demo that everybody looks at.
     */
    private function warehouse(): array
    {
        $area = function (string $key, string $label, string $category, int $places, array $box): array {
            return [
                'type' => 'area', 'key' => $key, 'layer' => 'interactive',
                'shape' => [
                    'kind' => 'rect', 'x' => $box[0], 'y' => $box[1],
                    'width' => $box[2], 'height' => $box[3],
                    'rotation' => 0, 'cornerRadius' => 18, 'points' => null,
                ],
                'translucent' => false, 'scale' => 1, 'categoryKey' => $category,
                'entrance' => 'Main door',
                'labeling' => [
                    'label' => $label, 'displayedLabel' => null, 'visible' => true,
                    'fontSize' => 24, 'positionX' => 0, 'positionY' => 0, 'locked' => false,
                ],
                'capacity' => ['type' => 'generalAdmission', 'places' => $places],
            ];
        };

        return [
            'version' => 2,
            'name' => 'The warehouse',
            'focalPoint' => ['x' => 600, 'y' => 150],
            'categories' => [
                ['key' => 'floor', 'label' => 'Floor', 'color' => '#e0526a', 'accessible' => false],
                ['key' => 'gallery', 'label' => 'Gallery', 'color' => '#b8860b', 'accessible' => false],
                ['key' => 'terrace', 'label' => 'Terrace', 'color' => '#3f9c6d', 'accessible' => false],
                ['key' => 'accessible', 'label' => 'Wheelchair space', 'color' => '#7b5ea7', 'accessible' => true],
            ],
            'floors' => [[
                'key' => '1',
                'name' => 'Level 1',
                'canvas' => ['width' => 1200, 'height' => 900, 'background' => null],
                'objects' => [
                    [
                        'type' => 'shape', 'key' => 'stage', 'layer' => 'background', 'kind' => 'stage',
                        'x' => 380, 'y' => 90, 'width' => 440, 'height' => 80, 'rotation' => 0,
                        'cornerRadius' => 6, 'points' => null, 'fill' => null, 'label' => 'Stage',
                    ],
                    $area('floor', 'Floor', 'floor', 900, [220, 220, 760, 320]),
                    $area('gallery', 'Gallery', 'gallery', 260, [220, 580, 360, 180]),
                    $area('terrace', 'Terrace', 'terrace', 180, [620, 580, 360, 180]),
                    $area('accessible-platform', 'Accessible platform', 'accessible', 24, [220, 790, 760, 70]),
                    ['type' => 'icon', 'key' => 'icon-entrance', 'layer' => 'foreground', 'name' => 'entrance', 'x' => 140, 'y' => 830, 'size' => 24, 'rotation' => 0],
                    ['type' => 'icon', 'key' => 'icon-bar', 'layer' => 'foreground', 'name' => 'bar', 'x' => 1060, 'y' => 400, 'size' => 24, 'rotation' => 0],
                    ['type' => 'icon', 'key' => 'icon-toilets', 'layer' => 'foreground', 'name' => 'toilets', 'x' => 1060, 'y' => 640, 'size' => 24, 'rotation' => 0],
                ],
            ]],
        ];
    }

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
