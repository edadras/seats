<?php

namespace Tests\Feature;

use App\Domain\Sites\SiteProvisioner;
use App\Models\Allocation;
use App\Models\Event;
use App\Models\EventPriceZone;
use App\Models\EventSeatOverride;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\TicketType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * A production that runs for more than one night.
 *
 * The thing to guard is the separation: a run is one thing to decide about on a programme page and
 * twenty-one separate inventories underneath. A seat sold on Tuesday must not be sold on Wednesday,
 * and a copy must not inherit a single ticket.
 */
class EventSeriesTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function a_repeat_copies_the_production_and_none_of_the_night(): void
    {
        $fixture = $this->makeSellableEvent(amount: 3500);
        $owner = $this->makeUser($fixture['tenant']);
        $event = $fixture['event'];

        app(TenantContext::class)->runAs($fixture['tenant'], function () use ($event, $fixture) {
            TicketType::create([
                'tenant_id' => $event->tenant_id,
                'event_id' => $event->id,
                'name' => 'Child',
                'kind' => 'percent_off',
                'value' => 50,
                'is_default' => false,
            ]);

            EventSeatOverride::create([
                'tenant_id' => $event->tenant_id,
                'event_id' => $event->id,
                'seat_id' => $fixture['seats'][0]->id,
                'blocked' => true,
                'note' => 'Held back after refund of order wc_1234',
            ]);

            $event->forceFill(['booking_fee_kind' => 'per_order', 'booking_fee_amount' => 250])->save();
        });

        // And one seat actually sold, which must not travel.
        $this->sell($fixture, 3);

        $made = $this->actingAs($owner)->postJson("/v1/events/{$event->id}/repeat", [
            'dates' => ['2026-11-14T19:30:00Z', '2026-11-15T19:30:00Z'],
        ])->assertCreated()->json('data');

        $this->assertCount(2, $made);
        $this->assertSame(['draft', 'draft'], array_column($made, 'status'),
            'On sale is a decision about a night, not something that happens by being copied.');

        $copy = app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => Event::findOrFail($made[0]['id'])
        );

        app(TenantContext::class)->runAs($fixture['tenant'], function () use ($copy, $event) {
            $this->assertSame(
                EventPriceZone::where('event_id', $event->id)->pluck('amount')->all(),
                EventPriceZone::where('event_id', $copy->id)->pluck('amount')->all(),
            );

            $this->assertSame(
                ['Child'],
                TicketType::where('event_id', $copy->id)->pluck('name')->all(),
            );

            $blocked = EventSeatOverride::where('event_id', $copy->id)->firstOrFail();

            $this->assertTrue((bool) $blocked->blocked, 'The pillar is there every night.');
            $this->assertNull($blocked->note, 'A note about one booking is not about next Tuesday.');

            $this->assertSame('per_order', $copy->booking_fee_kind);
            $this->assertSame(250, $copy->booking_fee_amount);

            // Nothing that was sold travels.
            $this->assertSame(0, Allocation::where('event_id', $copy->id)->count());
        });

        $this->assertNotSame($event->public_id, $copy->public_id);
    }

    #[Test]
    public function the_original_and_its_copies_end_up_in_one_run(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        $this->actingAs($owner)->postJson("/v1/events/{$fixture['event']->id}/repeat", [
            'dates' => ['2026-11-14T19:30:00Z'],
        ])->assertCreated();

        // Repeating again joins the same run rather than starting a second one.
        $this->actingAs($owner)->postJson("/v1/events/{$fixture['event']->id}/repeat", [
            'dates' => ['2026-11-21T19:30:00Z'],
        ])->assertCreated();

        $series = app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => Event::pluck('series_id')->unique()->filter()->values()
        );

        $this->assertCount(1, $series);
        $this->assertSame(3, app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => Event::whereNotNull('series_id')->count()
        ));
    }

    #[Test]
    public function a_seat_sold_on_one_night_is_free_on_another(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $seat = $fixture['seats'][0];

        $made = $this->actingAs($owner)->postJson("/v1/events/{$fixture['event']->id}/repeat", [
            'dates' => ['2026-11-14T19:30:00Z'],
        ])->assertCreated()->json('data');

        $copy = app(TenantContext::class)->runAs($fixture['tenant'], function () use ($made) {
            $copy = Event::findOrFail($made[0]['id']);
            $copy->forceFill(['status' => 'published'])->save();

            return $copy->fresh();
        });

        $this->postJson("/v1/embed/events/{$fixture['event']->public_id}/holds", [
            'seat_ids' => [$seat->id],
            'session_id' => 'sess_'.uniqid(),
        ])->assertCreated();

        // The same chair, another evening. Two nights, two inventories.
        $this->postJson("/v1/embed/events/{$copy->public_id}/holds", [
            'seat_ids' => [$seat->id],
            'session_id' => 'sess_'.uniqid(),
        ])->assertCreated();
    }

    #[Test]
    public function a_run_is_one_card_on_the_programme_and_a_list_of_dates_on_its_page(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $this->makeSite($fixture['tenant']);

        $made = $this->actingAs($owner)->postJson("/v1/events/{$fixture['event']->id}/repeat", [
            'dates' => ['2126-11-14T19:30:00Z', '2126-11-15T19:30:00Z'],
        ])->assertCreated()->json('data');

        app(TenantContext::class)->runAs($fixture['tenant'], function () use ($made) {
            foreach ($made as $row) {
                Event::findOrFail($row['id'])->forceFill(['status' => 'published'])->save();
            }
        });

        $programme = $this->get('http://northgate.test/')->assertOk();

        // One card for the run, and the count of what else there is.
        $programme->assertSee('more dates', escape: false);
        $this->assertSame(
            1,
            substr_count($programme->getContent(), 'class="event-card__name"'),
            'A three-week run is one thing to decide about, not twenty-one identical cards.'
        );

        $this->get('http://northgate.test/events/'.$fixture['event']->public_id)
            ->assertOk()
            ->assertSee('Other dates');
    }

    #[Test]
    public function only_somebody_who_manages_events_may_repeat_one(): void
    {
        $fixture = $this->makeSellableEvent();
        $box = $this->makeUser($fixture['tenant'], 'box_office');

        $this->actingAs($box)->postJson("/v1/events/{$fixture['event']->id}/repeat", [
            'dates' => ['2026-11-14T19:30:00Z'],
        ])->assertForbidden();
    }

    /* ------------------------------------------------------------------------------ helpers */

    private function sell(array $fixture, int $seatIndex): void
    {
        $hold = $this->postJson("/v1/embed/events/{$fixture['event']->public_id}/holds", [
            'seat_ids' => [$fixture['seats'][$seatIndex]->id],
            'session_id' => 'sess_'.uniqid(),
        ])->assertCreated()->json();

        $api = $this->makeApiClient($fixture['tenant']);
        $reference = 'wc_'.uniqid();
        $body = json_encode(['external_order_id' => $reference, 'hold_token' => $hold['hold_token']]);

        $this->call(
            'POST', '/v1/integrations/woocommerce/orders', [], [], [],
            $this->serverHeaders($this->signedHeaders(
                $api['key_id'], $api['secret'], 'POST', '/v1/integrations/woocommerce/orders', $body
            )),
            $body,
        )->assertCreated();

        $path = '/v1/integrations/woocommerce/orders/'.$reference.'/confirm';
        $payload = json_encode(['buyer' => ['name' => 'A Buyer', 'email' => 'buyer@example.test']]);

        $this->call(
            'POST', $path, [], [], [],
            $this->serverHeaders($this->signedHeaders($api['key_id'], $api['secret'], 'POST', $path, $payload)),
            $payload,
        )->assertOk();
    }

    private function makeSite($tenant): Site
    {
        return app(TenantContext::class)->runAs($tenant, function () use ($tenant) {
            $site = app(SiteProvisioner::class)->create($tenant->name);

            SiteDomain::create([
                'site_id' => $site->id,
                'hostname' => 'northgate.test',
                'is_primary' => true,
                'verification_token' => SiteDomain::newToken(),
                'verified_at' => now(),
            ]);

            $site->update(['status' => 'live']);

            return $site->fresh();
        });
    }
}
