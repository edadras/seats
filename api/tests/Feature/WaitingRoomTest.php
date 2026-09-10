<?php

namespace Tests\Feature;

use App\Domain\Queue\WaitingRoom;
use App\Domain\Sites\SiteProvisioner;
use App\Models\Event;
use App\Models\QueueTicket;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * The queue outside a big sale.
 *
 * Three claims are held up here, and they are the three that make the difference between a queue
 * and a lottery somebody can game.
 *
 * **Arriving early is worth nothing.** Everybody waiting when the doors open is drawn, not sorted
 * by arrival, so refreshing for an hour beforehand buys no advantage at all.
 *
 * **The room is counted, not decremented.** How many people are inside is a sum against a limit,
 * taken under an advisory lock — the same shape as a seat's availability — so two doors opening in
 * the same second cannot both let one more person in.
 *
 * **A door that only hides the picker is not a door.** The check is on the hold, where inventory
 * actually moves, and not merely on the page.
 */
class WaitingRoomTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function a_night_with_no_room_lets_everybody_straight_in(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);

        // Almost every night. There is no door and no page in front of the picker.
        $this->get('http://northgate.test/events/'.$fixture['event']->public_id)
            ->assertOk()
            ->assertSee('seatmap-widget');

        $this->hold($fixture, [0])->assertCreated();
    }

    #[Test]
    public function arriving_before_the_doors_open_buys_nothing(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->room($fixture, capacity: 2, onSaleAt: now()->addHour());

        // Ten people camp on the page an hour early. Nobody has a place: that is the design.
        for ($n = 0; $n < 10; $n++) {
            $this->newBrowser();
            $this->get('http://northgate.test/events/'.$fixture['event']->public_id)->assertOk();
        }

        $this->inTenant($fixture, function () {
            $this->assertSame(10, QueueTicket::where('status', 'lobby')->count());
            $this->assertSame(0, QueueTicket::whereNotNull('place')->count());
        });

        /*
         * The doors open, and the tenth person's browser is the first to notice.
         *
         * Not a fresh browser: somebody arriving *after* the doors open joins the back in arrival
         * order, which is right and would be an eleventh place — and this test is about the ten
         * who were already waiting.
         */
        $this->openTheDoors($fixture);
        $this->get('http://northgate.test/queue/'.$fixture['event']->public_id)->assertOk();

        $this->inTenant($fixture, function () {
            $places = QueueTicket::whereIn('status', ['queued', 'admitted'])
                ->orderBy('joined_at')
                ->pluck('place');

            // Everybody drawn, exactly once each, with no gaps and no repeats.
            $this->assertCount(10, $places->filter());
            $this->assertSame(range(1, 10), $places->sort()->values()->all());

            /*
             * And the order is not the order they arrived in — which is the whole claim.
             *
             * A shuffle of ten can come out in arrival order about once in three and a half
             * million runs. That is the assertion worth making anyway: if the draw were ever
             * quietly replaced by a sort, this fails every time rather than never.
             */
            $this->assertNotSame(range(1, 10), $places->values()->all());
        });
    }

    #[Test]
    public function only_as_many_as_the_room_holds_are_let_in(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->room($fixture, capacity: 3, onSaleAt: now()->subMinute());

        for ($n = 0; $n < 8; $n++) {
            $this->newBrowser();
            $this->get('http://northgate.test/events/'.$fixture['event']->public_id)->assertOk();
        }

        $this->inTenant($fixture, function () use ($fixture) {
            $room = app(WaitingRoom::class);
            $event = Event::findOrFail($fixture['event']->id);

            $this->assertSame(3, $room->inside($event));
            $this->assertSame(5, $room->waiting($event));
        });
    }

    #[Test]
    public function a_lapsed_lease_gives_the_place_away(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->room($fixture, capacity: 1, onSaleAt: now()->subMinute());

        // First in.
        $this->get('http://northgate.test/events/'.$fixture['event']->public_id)->assertOk();
        // Second waits.
        $this->newBrowser();
        $this->get('http://northgate.test/events/'.$fixture['event']->public_id)->assertOk();

        $second = $this->get('http://northgate.test/queue/'.$fixture['event']->public_id)->json();

        $this->assertSame('queued', $second['state']);

        // The first walks away from their desk. Without the sweep they would hold the room open
        // for the rest of the sale, and the room would look full while nobody was buying.
        $this->inTenant($fixture, fn () => QueueTicket::where('status', 'admitted')
            ->update(['expires_at' => now()->subMinute()]));

        $this->assertSame(
            'admitted',
            $this->get('http://northgate.test/queue/'.$fixture['event']->public_id)->json('state'),
        );
    }

    #[Test]
    public function the_door_is_on_the_hold_and_not_only_on_the_page(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->room($fixture, capacity: 1, onSaleAt: now()->subMinute());

        // Somebody is inside.
        $this->get('http://northgate.test/events/'.$fixture['event']->public_id)->assertOk();

        // And somebody else, outside, posts a hold straight at the store route with a console open.
        $this->newBrowser();
        $this->get('http://northgate.test/events/'.$fixture['event']->public_id)->assertOk();

        $this->hold($fixture, [0])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'waiting_your_turn');
    }

    #[Test]
    public function the_page_shows_the_room_instead_of_the_picker(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->room($fixture, capacity: 1, onSaleAt: now()->subMinute());

        $this->get('http://northgate.test/events/'.$fixture['event']->public_id)
            ->assertOk()
            // The first visitor walks straight in, so they get the picker.
            ->assertSee('seatmap-widget');

        $this->newBrowser();

        $waiting = $this->get('http://northgate.test/events/'.$fixture['event']->public_id)
            ->assertOk();

        // A seat map drawing behind a queue is a seat map being polled by everybody the queue
        // exists to hold back, so the second visitor gets the room and no picker at all.
        $waiting->assertSee('room__meter', false);
        $waiting->assertDontSee('seatmapBoot', false);
    }

    #[Test]
    public function buying_gives_the_place_back(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->room($fixture, capacity: 1, onSaleAt: now()->subMinute());

        $this->get('http://northgate.test/events/'.$fixture['event']->public_id)->assertOk();
        $this->hold($fixture, [0])->assertCreated();
        $this->post('http://northgate.test/checkout', [
            'name' => 'Amina Farsi', 'email' => 'amina@example.test', 'gateway' => 'offline',
        ])->assertRedirect();

        // The buyer has what they came for. Holding their slot afterwards keeps somebody else out.
        $this->inTenant($fixture, function () use ($fixture) {
            $this->assertSame('left', QueueTicket::orderByDesc('created_at')->firstOrFail()->status);
            $this->assertSame(0, app(WaitingRoom::class)->inside(Event::findOrFail($fixture['event']->id)));
        });
    }

    #[Test]
    public function a_reload_does_not_join_the_queue_twice(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->room($fixture, capacity: 1, onSaleAt: now()->subMinute());

        $this->get('http://northgate.test/events/'.$fixture['event']->public_id)->assertOk();

        for ($n = 0; $n < 5; $n++) {
            $this->get('http://northgate.test/queue/'.$fixture['event']->public_id)->assertOk();
        }

        $this->inTenant($fixture, fn () => $this->assertSame(1, QueueTicket::count()));
    }

    #[Test]
    public function leaving_gives_up_the_place(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->room($fixture, capacity: 1, onSaleAt: now()->subMinute());

        $this->get('http://northgate.test/events/'.$fixture['event']->public_id)->assertOk();
        $this->newBrowser();
        $this->get('http://northgate.test/events/'.$fixture['event']->public_id)->assertOk();

        // The one inside decides against it and says so, rather than sitting on the slot until the
        // lease runs out.
        $this->newBrowser();
        $inside = $this->inTenant($fixture, fn () => QueueTicket::where('status', 'admitted')->firstOrFail());
        $this->withSession(['seatmap_queue' => [$fixture['event']->id => $inside->token]]);

        $this->post('http://northgate.test/queue/'.$fixture['event']->public_id.'/leave')
            ->assertRedirect('/');

        $this->assertSame('left', $inside->fresh()->status);
    }

    #[Test]
    public function the_organiser_watches_the_door(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->room($fixture, capacity: 2, onSaleAt: now()->subMinute());

        for ($n = 0; $n < 5; $n++) {
            $this->newBrowser();
            $this->get('http://northgate.test/events/'.$fixture['event']->public_id)->assertOk();
        }

        $user = $this->makeUser($fixture['tenant']);

        $this->actingAs($user)->getJson('/v1/events/'.$fixture['event']->id.'/queue')
            ->assertOk()
            ->assertJsonPath('guarded', true)
            ->assertJsonPath('open', true)
            ->assertJsonPath('inside', 2)
            ->assertJsonPath('waiting', 3);
    }

    #[Test]
    public function a_night_with_no_door_says_so_rather_than_pretending(): void
    {
        $fixture = $this->makeSellableEvent();
        $user = $this->makeUser($fixture['tenant']);

        $this->actingAs($user)->getJson('/v1/events/'.$fixture['event']->id.'/queue')
            ->assertOk()
            ->assertJsonPath('guarded', false)
            ->assertJsonPath('inside', 0);
    }

    /* ------------------------------------------------------------------------------ helpers */

    private function room(array $fixture, int $capacity, $onSaleAt, int $minutes = 10): void
    {
        $this->inTenant($fixture, fn () => Event::whereKey($fixture['event']->id)->update([
            'waiting_room' => true,
            'waiting_room_capacity' => $capacity,
            'waiting_room_minutes' => $minutes,
            'on_sale_at' => $onSaleAt,
        ]));
    }

    private function openTheDoors(array $fixture): void
    {
        $this->inTenant($fixture, fn () => Event::whereKey($fixture['event']->id)
            ->update(['on_sale_at' => now()->subMinute()]));
    }

    /** @param  list<int>  $seats */
    private function hold(array $fixture, array $seats)
    {
        return $this->postJson('http://northgate.test/_store/hold', [
            'event_public_id' => $fixture['event']->public_id,
            'seat_ids' => array_map(fn (int $i) => $fixture['seats'][$i]->id, $seats),
        ]);
    }

    private function newBrowser(): void
    {
        $this->app['session']->flush();
        $this->app['session']->regenerate();
    }

    private function inTenant(array $fixture, callable $work)
    {
        return app(TenantContext::class)->runAs($fixture['tenant'], $work);
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
