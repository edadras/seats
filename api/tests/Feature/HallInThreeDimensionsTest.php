<?php

namespace Tests\Feature;

use App\Domain\SeatMaps\SeatMapPublisher;
use App\Domain\SeatMaps\SeatMapValidator;
use App\Models\Event;
use App\Models\SeatMapVersion;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * The room an organiser sets up is the room a buyer is shown.
 *
 * The arithmetic lives in `shared/hall-3d` and is pinned by `tools/hall3d-check.mjs`; what the
 * server has to promise is narrower and just as load-bearing. **The heights survive the journey**:
 * a chart validated, published and handed to a browser must still say how high the balcony is, or
 * the designer and the picker are describing two different halls.
 *
 * And **a night is never moved onto a new chart behind somebody's back**. Publishing a chart the
 * afternoon before a show must not shift the seats people have already bought; taking the new one
 * up is a decision, and one that is refused outright if it would leave a sold seat pointing at a
 * chair that no longer exists.
 */
class HallInThreeDimensionsTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    /** @return array<string, mixed> */
    private function room(): array
    {
        return [
            'enabled' => true,
            'rake' => 9,
            'stage' => ['height' => 88, 'depth' => 220, 'width' => 0, 'riser' => true],
            'sections' => [
                'stalls' => ['base' => 0, 'rake' => 9, 'skirt' => false],
                'balcony' => ['base' => 165, 'rake' => 20, 'skirt' => true],
            ],
        ];
    }

    #[Test]
    public function the_heights_survive_validation_publishing_and_the_journey_to_a_browser(): void
    {
        $night = $this->makeSellableEvent(rows: 3, perRow: 5);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $geometry = $night['map']->publishedVersion->geometry;
            $geometry['view3d'] = $this->room();

            // The validator is the authority on what may go on sale, and it must not object to a
            // chart that says how high its balcony is.
            $report = SeatMapValidator::make()->validate($geometry);

            $this->assertTrue($report['valid'], json_encode($report['errors']));

            $version = SeatMapVersion::create([
                'seat_map_id' => $night['map']->id,
                'version' => 2,
                'status' => 'draft',
                'geometry' => $geometry,
            ]);

            app(SeatMapPublisher::class)->publish($night['map'], $version);

            $night['event']->forceFill(['seat_map_version_id' => $version->id])->save();
        });

        // And the buyer's browser is handed it, exactly as it was written.
        $payload = $this->getJson('/v1/embed/events/'.$night['event']->public_id.'/seat-map')
            ->assertOk()
            ->json();

        $this->assertTrue($payload['geometry']['view3d']['enabled']);
        $this->assertSame(88, $payload['geometry']['view3d']['stage']['height']);
        $this->assertSame(165, $payload['geometry']['view3d']['sections']['balcony']['base']);
        $this->assertSame(20, $payload['geometry']['view3d']['sections']['balcony']['rake']);
    }

    #[Test]
    public function a_night_stays_on_its_own_chart_until_somebody_says_otherwise(): void
    {
        $night = $this->makeSellableEvent(rows: 3, perRow: 5);
        $manager = $this->makeUser($night['tenant'], 'manager');
        $before = $night['event']->seat_map_version_id;

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $geometry = $night['map']->publishedVersion->geometry;
            $geometry['view3d'] = $this->room();

            app(SeatMapPublisher::class)->publish($night['map'], SeatMapVersion::create([
                'seat_map_id' => $night['map']->id,
                'version' => 2,
                'status' => 'draft',
                'geometry' => $geometry,
            ]));
        });

        // Publishing moved nothing: the night is still selling the chart it was put on.
        $this->assertSame($before, app(TenantContext::class)->runAs(
            $night['tenant'],
            fn () => Event::find($night['event']->id)->seat_map_version_id
        ));

        $this->asMember($manager)->getJson('/v1/events/'.$night['event']->id)
            ->assertOk()
            ->assertJsonPath('chart_outdated', true);

        $this->asMember($manager)->postJson('/v1/events/'.$night['event']->id.'/chart-version')
            ->assertOk()
            ->assertJsonPath('chart_outdated', false);

        $this->assertNotSame($before, app(TenantContext::class)->runAs(
            $night['tenant'],
            fn () => Event::find($night['event']->id)->seat_map_version_id
        ));
    }

    #[Test]
    public function a_chart_that_has_lost_a_sold_seat_is_refused(): void
    {
        $night = $this->makeSellableEvent(rows: 3, perRow: 5);
        $manager = $this->makeUser($night['tenant'], 'manager');

        $client = $this->makeApiClient($night['tenant'])['client'];

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night, $client) {
            // One seat sold, the ordinary way: held, registered, confirmed.
            $hold = app(\App\Domain\Inventory\HoldService::class)->create(
                $night['event'],
                // The last row's last chair, which is the row the redrawn chart below drops.
                [$night['seats']->last()->id],
                'session-'.uniqid(),
            );

            [$order] = app(\App\Domain\Orders\OrderService::class)->register(
                $client,
                'ORD-'.strtoupper(uniqid()),
                $hold->token,
                ['name' => 'Sam Buyer', 'email' => 'sam@example.test'],
            );

            app(\App\Domain\Orders\OrderService::class)->confirm($order->fresh());

            // A chart redrawn with one row fewer, which is one row of chairs that no longer exist.
            $geometry = $this->geometry(rows: 2, perRow: 5);
            $geometry['view3d'] = $this->room();

            app(SeatMapPublisher::class)->publish($night['map'], SeatMapVersion::create([
                'seat_map_id' => $night['map']->id,
                'version' => 2,
                'status' => 'draft',
                'geometry' => $geometry,
            ]));
        });

        $this->asMember($manager)->postJson('/v1/events/'.$night['event']->id.'/chart-version')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'chart_missing_sold_seats');

        // And the night is left exactly where it was, selling a chart whose chairs all exist.
        $this->asMember($manager)->getJson('/v1/events/'.$night['event']->id)
            ->assertJsonPath('chart_outdated', true);
    }

    #[Test]
    public function a_chart_that_says_nothing_about_a_room_is_flat_and_says_so(): void
    {
        $night = $this->makeSellableEvent(rows: 2, perRow: 4);

        $payload = $this->getJson('/v1/embed/events/'.$night['event']->public_id.'/seat-map')
            ->assertOk()
            ->json();

        // Absent rather than defaulted: a hall nobody has measured must not be published as one
        // somebody has, and the picker's 3D button is offered on exactly the charts that say so.
        $this->assertArrayNotHasKey('view3d', $payload['geometry']);
    }
}
