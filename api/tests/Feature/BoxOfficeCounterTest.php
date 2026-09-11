<?php

namespace Tests\Feature;

use App\Models\Allocation;
use App\Models\AuditLog;
use App\Models\ExternalOrder;
use App\Models\Ticket;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * Selling at the window.
 *
 * The claim under test is that a counter sale is a real sale — the same holds, the same
 * allocations, the same tickets — and not a second order path with its own idea of inventory. So
 * these check that a seat sold at the counter cannot then be sold on the website, that a comp is
 * worth nothing, and that the whole thing is refused to somebody without the permission.
 */
class BoxOfficeCounterTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function the_counter_shows_what_is_free_and_what_has_gone(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        // One seat put beyond reach by somebody else's cart.
        $this->postJson("/v1/embed/events/{$fixture['event']->public_id}/holds", [
            'seat_ids' => [$fixture['seats'][0]->id],
            'session_id' => 'sess_'.uniqid(),
        ])->assertCreated();

        $hall = $this->actingAs($owner)
            ->getJson("/v1/events/{$fixture['event']->id}/counter")
            ->assertOk()
            ->json();

        $seats = collect($hall['sections'])->flatMap(
            fn ($section) => collect($section['rows'])->flatMap(fn ($row) => $row['seats'])
        );

        $held = $seats->firstWhere('id', $fixture['seats'][0]->id);

        // Shown, not hidden: a clerk asked for that seat needs to be told it has gone.
        $this->assertNotNull($held);
        $this->assertNotSame('available', $held['state']);
        $this->assertTrue($seats->contains(fn ($seat) => 'available' === $seat['state']));
    }

    #[Test]
    public function the_window_is_handed_the_same_hall_the_buyer_is_looking_at(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $event = $fixture['event'];

        $counter = $this->actingAs($owner)
            ->getJson("/v1/events/{$event->id}/hall")
            ->assertOk()
            ->json();

        $public = $this->getJson("/v1/embed/events/{$event->public_id}")->assertOk()->json();
        $publicMap = $this->getJson("/v1/embed/events/{$event->public_id}/seat-map")->assertOk()->json();

        /*
         * The same room, from the same presenter.
         *
         * This is the whole claim: the panel does not draw a hall of its own, it runs the buyer's
         * picker. Two presenters that drifted would be two halls — a zone missing from one, a
         * ticket type present in the other — and a window that quietly disagrees with the website
         * about what a seat costs.
         */
        $this->assertSame($public, $counter['event']);
        $this->assertSame($publicMap['geometry'], $counter['geometry']);
    }

    #[Test]
    public function the_counters_availability_sees_the_house_seat_and_says_whose_it_is(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $event = $fixture['event'];
        $kept = $fixture['seats'][0];

        app(TenantContext::class)->runAs($fixture['tenant'], fn () => \App\Models\EventSeatOverride::create([
            'tenant_id' => $fixture['tenant']->id,
            'event_id' => $event->id,
            'seat_id' => $kept->id,
            // Blocked to the website, which is what a house seat is: on sale at one window only.
            'blocked' => true,
            'held_for' => 'The director’s mother',
        ]));

        $atTheWindow = collect(
            $this->actingAs($owner)
                ->getJson("/v1/events/{$event->id}/hall/availability")
                ->assertOk()
                ->json('seats')
        )->firstWhere('seat_id', $kept->id);

        // On sale here, with the name on it. That is what holding a seat back is *for*: somebody is
        // going to be handed it on the night, and the person handing it over works at this window.
        $this->assertSame('available', $atTheWindow['state']);
        $this->assertSame('The director’s mother', $atTheWindow['held_for']);

        $online = collect(
            $this->getJson("/v1/embed/events/{$event->public_id}/availability")->assertOk()->json('seats')
        )->firstWhere('seat_id', $kept->id);

        // And to the public it is gone, with no name and no hint that there is one.
        $this->assertNotSame('available', $online['state']);
        $this->assertArrayNotHasKey('held_for', $online);
    }

    #[Test]
    public function a_window_sale_allocates_seats_and_issues_tickets(): void
    {
        $fixture = $this->makeSellableEvent(amount: 3000);
        $owner = $this->makeUser($fixture['tenant']);

        $sale = $this->actingAs($owner)->postJson("/v1/events/{$fixture['event']->id}/sell", [
            'seat_ids' => [$fixture['seats'][0]->id, $fixture['seats'][1]->id],
            'buyer' => ['name' => 'Walk-up buyer'],
            'payment' => 'paid',
        ])->assertCreated()->json();

        $this->assertSame(6000, $sale['total_amount']);
        $this->assertSame(2, $sale['seats']);
        $this->assertStringStartsWith('bo-', $sale['reference']);

        $tickets = app(TenantContext::class)->runAs($fixture['tenant'], function () use ($fixture) {
            $allocations = Allocation::where('event_id', $fixture['event']->id)
                ->where('status', 'active')->get();

            return Ticket::whereIn('allocation_id', $allocations->pluck('id'))->get();
        });

        $this->assertCount(2, $tickets, 'One ticket per seat, the same as a website sale.');
    }

    #[Test]
    public function a_seat_sold_at_the_window_cannot_be_sold_again_online(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $seat = $fixture['seats'][0];

        $this->actingAs($owner)->postJson("/v1/events/{$fixture['event']->id}/sell", [
            'seat_ids' => [$seat->id],
            'buyer' => ['name' => 'Walk-up buyer'],
            'payment' => 'paid',
        ])->assertCreated();

        // One inventory, not two.
        $this->postJson("/v1/embed/events/{$fixture['event']->public_id}/holds", [
            'seat_ids' => [$seat->id],
            'session_id' => 'sess_'.uniqid(),
        ])->assertStatus(409)->assertJsonPath('error.code', 'seat_unavailable');
    }

    #[Test]
    public function the_counter_loses_a_seat_that_somebody_online_took_first(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $seat = $fixture['seats'][0];

        $this->postJson("/v1/embed/events/{$fixture['event']->public_id}/holds", [
            'seat_ids' => [$seat->id],
            'session_id' => 'sess_'.uniqid(),
        ])->assertCreated();

        $this->actingAs($owner)->postJson("/v1/events/{$fixture['event']->id}/sell", [
            'seat_ids' => [$seat->id],
            'buyer' => ['name' => 'Walk-up buyer'],
            'payment' => 'paid',
        ])->assertStatus(409);
    }

    #[Test]
    public function a_comp_is_worth_nothing_and_says_so(): void
    {
        $fixture = $this->makeSellableEvent(amount: 4500);
        $owner = $this->makeUser($fixture['tenant']);

        $sale = $this->actingAs($owner)->postJson("/v1/events/{$fixture['event']->id}/sell", [
            'seat_ids' => [$fixture['seats'][2]->id],
            'buyer' => ['name' => 'The director’s mother'],
            'payment' => 'comp',
            'note' => 'Opening night',
        ])->assertCreated()->json();

        // A report that counted free seats as revenue would overstate the evening by exactly the
        // generosity of the house.
        $this->assertSame(0, $sale['total_amount']);

        $order = app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => ExternalOrder::where('external_order_id', $sale['reference'])->firstOrFail()
        );

        $this->assertSame('comp', $order->metadata['payment']);
        $this->assertSame('Opening night', $order->metadata['note']);
        $this->assertSame('confirmed', $order->status, 'The ticket is real even though it was free.');
    }

    #[Test]
    public function a_concession_can_be_sold_at_the_window(): void
    {
        $fixture = $this->makeSellableEvent(amount: 4000);
        $owner = $this->makeUser($fixture['tenant']);

        $child = app(TenantContext::class)->runAs($fixture['tenant'], fn () => \App\Models\TicketType::create([
            'tenant_id' => $fixture['tenant']->id,
            'event_id' => $fixture['event']->id,
            'name' => 'Child',
            'kind' => 'percent_off',
            'value' => 50,
        ]));

        $sale = $this->actingAs($owner)->postJson("/v1/events/{$fixture['event']->id}/sell", [
            'seat_ids' => [$fixture['seats'][0]->id],
            'seat_types' => [$fixture['seats'][0]->id => $child->id],
            'buyer' => ['name' => 'A family'],
            'payment' => 'paid',
        ])->assertCreated()->json();

        $this->assertSame(2000, $sale['total_amount']);
    }

    #[Test]
    public function sending_the_tickets_needs_somewhere_to_send_them(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        $this->actingAs($owner)->postJson("/v1/events/{$fixture['event']->id}/sell", [
            'seat_ids' => [$fixture['seats'][0]->id],
            'buyer' => ['name' => 'Walk-up buyer'],
            'payment' => 'paid',
            'send_tickets' => true,
        ])->assertStatus(422)->assertJsonPath('error.code', 'email_required');

        // And nothing was sold while the request was being refused.
        $this->assertSame(0, app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => ExternalOrder::count()
        ));
    }

    #[Test]
    public function the_sale_is_recorded_against_whoever_made_it(): void
    {
        $fixture = $this->makeSellableEvent();
        $clerk = $this->makeUser($fixture['tenant'], 'box_office');

        $this->actingAs($clerk)->postJson("/v1/events/{$fixture['event']->id}/sell", [
            'seat_ids' => [$fixture['seats'][0]->id],
            'buyer' => ['name' => 'Walk-up buyer'],
            'payment' => 'owed',
        ])->assertCreated();

        $entry = app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => AuditLog::where('action', 'order.sold_at_counter')->firstOrFail()
        );

        $this->assertSame($clerk->id, $entry->actor_id);
        $this->assertSame('owed', $entry->context['payment']);
    }

    #[Test]
    public function somebody_who_only_reads_cannot_sell(): void
    {
        $fixture = $this->makeSellableEvent();
        $viewer = $this->makeUser($fixture['tenant'], 'viewer');

        $this->actingAs($viewer)->getJson("/v1/events/{$fixture['event']->id}/counter")->assertForbidden();
        $this->actingAs($viewer)->postJson("/v1/events/{$fixture['event']->id}/sell", [
            'seat_ids' => [$fixture['seats'][0]->id],
            'buyer' => ['name' => 'Nobody'],
            'payment' => 'comp',
        ])->assertForbidden();
    }
}
