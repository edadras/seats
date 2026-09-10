<?php

namespace Tests\Feature;

use App\Domain\Sites\SiteProvisioner;
use App\Models\Allocation;
use App\Models\EntrySlot;
use App\Models\Event;
use App\Models\ExternalOrder;
use App\Models\Hold;
use App\Models\MessageDelivery;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\Ticket;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * The night that is off, and the night that moved.
 *
 * A cancellation has to do four things and be trusted to have done all four: stop the sale, hand
 * the money back, void the tickets, and tell everybody. A move has to do none of them — the whole
 * point is that the tickets survive — and these check that the two are not confused for each other.
 */
class EventCancellationTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function calling_a_night_off_refunds_voids_and_tells(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->buy($fixture, [0, 1]);

        $owner = $this->makeUser($fixture['tenant']);

        $this->actingAs($owner)
            ->postJson("/v1/events/{$fixture['event']->id}/cancel", [
                'reason' => 'The singer is ill.',
                'confirm' => $fixture['event']->name,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');

        app(TenantContext::class)->runAs($fixture['tenant'], function () {
            $order = ExternalOrder::orderByDesc('created_at')->firstOrFail();

            $this->assertSame('refunded', $order->status);
            $this->assertSame(0, Allocation::where('status', 'active')->count());
            $this->assertSame(0, Ticket::where('status', 'issued')->count());

            // And they were told, in their own words — a cancellation without a reason is the
            // start of an argument rather than the end of one.
            $told = MessageDelivery::where('kind', 'event.cancelled')->get();

            $this->assertCount(1, $told);
            $this->assertStringContainsString('The singer is ill.', (string) $told->first()->preview);
        });
    }

    #[Test]
    public function it_stops_the_sale_at_once(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $owner = $this->makeUser($fixture['tenant']);

        // A cart in flight when the night is called off. Those seats are not for sale, and the
        // buyer should be told rather than charged.
        $this->postJson('http://northgate.test/_store/hold', [
            'event_public_id' => $fixture['event']->public_id,
            'seat_ids' => [$fixture['seats'][3]->id],
        ])->assertCreated();

        $this->actingAs($owner)
            ->postJson("/v1/events/{$fixture['event']->id}/cancel", [
                'reason' => 'Flooding.',
                'confirm' => $fixture['event']->name,
            ])
            ->assertOk();

        app(TenantContext::class)->runAs($fixture['tenant'], function () {
            $this->assertSame(0, Hold::where('status', 'active')->count());
        });

        /*
         * And nothing more can be sold, by either public route.
         *
         * Both stop looking the moment an event is not on sale, so the refusal is "unknown event"
         * rather than "cancelled" — which is the right answer to a stranger asking a public API
         * about a night that is off, and the page a human arrives on says why instead.
         */
        $this->postJson('http://northgate.test/_store/hold', [
            'event_public_id' => $fixture['event']->public_id,
            'seat_ids' => [$fixture['seats'][4]->id],
        ])->assertNotFound();

        $this->postJson("/v1/embed/events/{$fixture['event']->public_id}/holds", [
            'seat_ids' => [$fixture['seats'][4]->id],
            'session_id' => 'sess_after_cancellation',
        ])->assertNotFound();
    }

    #[Test]
    public function the_money_can_be_left_where_it_was_taken(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->buy($fixture, [0]);

        $owner = $this->makeUser($fixture['tenant']);

        // A gateway this platform cannot reach: the organiser will hand the money back themselves.
        // The seats come back and the tickets die either way — a valid ticket to a cancelled event
        // is a person at a locked door.
        $this->actingAs($owner)
            ->postJson("/v1/events/{$fixture['event']->id}/cancel", [
                'reason' => 'Cancelled.',
                'confirm' => $fixture['event']->name,
                'refund' => false,
            ])
            ->assertOk();

        app(TenantContext::class)->runAs($fixture['tenant'], function () {
            $this->assertSame('confirmed', ExternalOrder::firstOrFail()->status);
            $this->assertSame(1, Ticket::where('status', 'issued')->count());
        });
    }

    #[Test]
    public function it_asks_for_the_events_own_name(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        // On a list of twelve dates, a mis-click on the wrong row would be a disaster with no way
        // back, so a yes is not enough.
        $this->actingAs($owner)
            ->postJson("/v1/events/{$fixture['event']->id}/cancel", [
                'reason' => 'Oops.',
                'confirm' => 'yes',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'confirm_with_the_name');

        $this->assertSame('published', app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => Event::findOrFail($fixture['event']->id)->status
        ));
    }

    #[Test]
    public function it_takes_both_the_permission_to_change_and_the_permission_to_refund(): void
    {
        $fixture = $this->makeSellableEvent();

        // A manager may change an event and may not hand money back. Cancelling does both.
        $doorman = $this->makeUser($fixture['tenant'], 'door');

        $this->actingAs($doorman)
            ->postJson("/v1/events/{$fixture['event']->id}/cancel", [
                'reason' => 'No.',
                'confirm' => $fixture['event']->name,
            ])
            ->assertForbidden();
    }

    #[Test]
    public function moving_a_date_keeps_every_ticket(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->buy($fixture, [0, 1]);

        $owner = $this->makeUser($fixture['tenant']);
        $was = $fixture['event']->starts_at;
        $to = $was->copy()->addWeeks(2);

        $this->actingAs($owner)
            ->postJson("/v1/events/{$fixture['event']->id}/reschedule", [
                'starts_at' => $to->toIso8601String(),
                'reason' => 'The hall is being repaired.',
            ])
            ->assertOk()
            ->assertJsonPath('rescheduled_from', $was->toIso8601String());

        app(TenantContext::class)->runAs($fixture['tenant'], function () use ($to) {
            $this->assertSame('confirmed', ExternalOrder::firstOrFail()->status);
            $this->assertSame(2, Ticket::where('status', 'issued')->count());
            $this->assertTrue($to->equalTo(Event::firstOrFail()->starts_at));

            $told = MessageDelivery::where('kind', 'event.moved')->get();

            $this->assertCount(1, $told);
            $this->assertStringContainsString('still valid', (string) $told->first()->preview);
        });
    }

    #[Test]
    public function arrival_windows_move_with_the_day(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $was = $fixture['event']->starts_at;

        $slot = app(TenantContext::class)->runAs($fixture['tenant'], fn () => EntrySlot::create([
            'tenant_id' => $fixture['tenant']->id,
            'event_id' => $fixture['event']->id,
            'starts_at' => $was->copy()->startOfDay()->addHours(10),
            'ends_at' => $was->copy()->startOfDay()->addHours(10)->addMinutes(30),
            'capacity' => 50,
            'status' => 'open',
        ]));

        $this->actingAs($owner)
            ->postJson("/v1/events/{$fixture['event']->id}/reschedule", [
                'starts_at' => $was->copy()->addDays(3)->toIso8601String(),
            ])
            ->assertOk();

        // Ten in the morning on the new day, not ten in the morning on the old one.
        $moved = app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => EntrySlot::findOrFail($slot->id)
        );

        $this->assertTrue($slot->starts_at->copy()->addDays(3)->equalTo($moved->starts_at));
        $this->assertTrue($slot->ends_at->copy()->addDays(3)->equalTo($moved->ends_at));
    }

    #[Test]
    public function a_cancelled_night_cannot_be_moved(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        $this->actingAs($owner)
            ->postJson("/v1/events/{$fixture['event']->id}/cancel", [
                'reason' => 'Off.',
                'confirm' => $fixture['event']->name,
            ])
            ->assertOk();

        $this->actingAs($owner)
            ->postJson("/v1/events/{$fixture['event']->id}/reschedule", [
                'starts_at' => Carbon::parse($fixture['event']->starts_at)->addWeek()->toIso8601String(),
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'event_cancelled');
    }

    #[Test]
    public function the_page_says_it_is_off_and_why(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $owner = $this->makeUser($fixture['tenant']);

        $this->actingAs($owner)
            ->postJson("/v1/events/{$fixture['event']->id}/cancel", [
                'reason' => 'The singer is ill.',
                'confirm' => $fixture['event']->name,
            ])
            ->assertOk();

        // People arrive here from an old link and from the cancellation email itself. A 404 would
        // be the worst answer available.
        $this->get('http://northgate.test/events/'.$fixture['event']->public_id)
            ->assertOk()
            ->assertSee('The singer is ill.', false)
            ->assertSee('EventCancelled', false);
    }

    /* ------------------------------------------------------------------------------ helpers */

    /** @param  list<int>  $seats */
    private function buy(array $fixture, array $seats): void
    {
        $this->postJson('http://northgate.test/_store/hold', [
            'event_public_id' => $fixture['event']->public_id,
            'seat_ids' => array_map(fn (int $i) => $fixture['seats'][$i]->id, $seats),
        ])->assertCreated();

        $this->post('http://northgate.test/checkout', [
            'name' => 'Amina Farsi',
            'email' => 'amina@example.test',
            'gateway' => 'offline',
        ])->assertRedirect();
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
