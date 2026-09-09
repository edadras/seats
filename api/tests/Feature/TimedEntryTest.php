<?php

namespace Tests\Feature;

use App\Domain\Sites\SiteProvisioner;
use App\Models\Allocation;
use App\Models\EntrySlot;
use App\Models\Event;
use App\Models\ExternalOrder;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * Timed entry: the same day sold in windows, each with its own capacity.
 *
 * The checks that matter are the two the feature exists for. A window only ever holds as many
 * people as it was given — which is a sum against a limit, not a row per chair, so it is checked
 * the way a standing area is. And what a buyer was told rides all the way to the door: onto the
 * booking, onto the ticket, onto the door list, and back out of a scan.
 */
class TimedEntryTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function an_event_with_windows_will_not_sell_without_one(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->windows($fixture, [[10, 2]]);

        $this->postJson('http://northgate.test/_store/hold', [
            'event_public_id' => $fixture['event']->public_id,
            'seat_ids' => [$fixture['seats'][0]->id],
        ])->assertStatus(422)->assertJsonPath('error.code', 'entry_slot_required');
    }

    #[Test]
    public function an_event_without_windows_refuses_one(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);

        // Silently dropping it would print a ticket with no arrival time on it and nobody would
        // know why, so it is a refusal rather than a shrug.
        $this->postJson('http://northgate.test/_store/hold', [
            'event_public_id' => $fixture['event']->public_id,
            'seat_ids' => [$fixture['seats'][0]->id],
            'entry_slot_id' => (string) \Illuminate\Support\Str::uuid(),
        ])->assertStatus(422)->assertJsonPath('error.code', 'entry_slot_not_offered');
    }

    #[Test]
    public function a_window_holds_exactly_as_many_people_as_it_was_given(): void
    {
        $fixture = $this->makeSellableEvent(rows: 3, perRow: 5);
        $this->makeSite($fixture['tenant']);
        [$slot] = $this->windows($fixture, [[10, 2]]);

        $this->hold($fixture, [0, 1], $slot->id)->assertCreated();

        // The third seat is free, the ten o'clock is not.
        $this->hold($fixture, [2], $slot->id)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'entry_slot_full');
    }

    #[Test]
    public function a_hold_that_expires_gives_its_places_back(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        [$slot] = $this->windows($fixture, [[10, 1]]);

        $this->hold($fixture, [0], $slot->id)->assertCreated();

        // An abandoned cart must not hold a window shut for the rest of the day: the places go
        // back the moment the hold does.
        $this->postJson('http://northgate.test/_store/release')->assertOk();

        $this->hold($fixture, [1], $slot->id)->assertCreated();
    }

    #[Test]
    public function the_windows_on_either_side_stay_open(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        [$ten, $eleven] = $this->windows($fixture, [[10, 1], [11, 1]]);

        $this->hold($fixture, [0], $ten->id)->assertCreated();
        $this->newBrowser();

        $this->hold($fixture, [1], $eleven->id)->assertCreated();
    }

    #[Test]
    public function a_closed_window_is_no_longer_sold(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        [$ten, $eleven] = $this->windows($fixture, [[10, 5], [11, 5]]);

        app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => EntrySlot::whereKey($ten->id)->update(['status' => 'closed'])
        );

        $this->hold($fixture, [0], $ten->id)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'entry_slot_closed');
    }

    #[Test]
    public function the_window_a_buyer_chose_is_on_their_booking_and_their_ticket(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        [$slot] = $this->windows($fixture, [[10, 4]]);

        $this->hold($fixture, [0, 1], $slot->id)->assertCreated();
        $this->pay();

        [$allocations, $order] = app(TenantContext::class)->runAs($fixture['tenant'], fn () => [
            Allocation::where('status', 'active')->get(),
            ExternalOrder::orderByDesc('created_at')->firstOrFail(),
        ]);

        $this->assertCount(2, $allocations);

        foreach ($allocations as $allocation) {
            $this->assertSame($slot->id, $allocation->entry_slot_id);
            // The window's own times are copied beside the id: an organiser who rewrites the
            // timetable must not change what a ticket already sold says.
            $this->assertTrue($slot->starts_at->equalTo($allocation->entry_starts_at));
            $this->assertTrue($slot->ends_at->equalTo($allocation->entry_ends_at));
        }

        // And the buyer's own page tells them when to turn up — in the venue's own clock, which
        // is the whole point: a window is a time to stand outside a building.
        $this->get('http://northgate.test/order/'.$order->external_order_id)
            ->assertOk()
            ->assertSee(\App\Domain\Events\EntrySlots::window(
                $slot->starts_at, $slot->ends_at, $fixture['event']->timezone
            ), false);
    }

    #[Test]
    public function the_door_reads_the_night_by_window(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        [$ten, $eleven] = $this->windows($fixture, [[10, 4], [11, 4]]);

        $this->hold($fixture, [0], $ten->id)->assertCreated();
        $this->pay('Dana Scully', 'dana@example.test');
        $this->newBrowser();

        $this->hold($fixture, [1], $eleven->id)->assertCreated();
        $this->pay('Amir Rahimi', 'amir@example.test');

        $owner = $this->makeUser($fixture['tenant']);

        $list = $this->actingAs($owner)
            ->getJson("/v1/events/{$fixture['event']->id}/door-list")
            ->assertOk()
            ->json();

        $this->assertCount(2, $list['data']);
        $this->assertCount(2, $list['entry_slots']);
        $this->assertNotNull($list['data'][0]['entry_starts_at']);

        // One window at a time is how a door actually works: the ten o'clock queue is one list.
        $ten = $this->actingAs($owner)
            ->getJson("/v1/events/{$fixture['event']->id}/door-list?entry_slot_id={$ten->id}")
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $ten);
        $this->assertSame('Dana Scully', $ten[0]['name']);
    }

    #[Test]
    public function a_scan_says_which_window_and_whether_they_are_in_it(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        [$slot] = $this->windows($fixture, [[10, 4]]);

        $this->hold($fixture, [0], $slot->id)->assertCreated();
        $this->pay('Dana Scully', 'dana@example.test');

        // The plaintext code exists in one place and for one page: the session the confirmation
        // is rendered from. A minute later there is nothing left to read.
        $token = collect(session('seatmap_tokens', []))->first();
        $device = $this->pairDevice($fixture);

        // The scan happens the day before the window opens, and says so rather than refusing:
        // a scanner that turned away a valid ticket would put a queue at the door with no way out.
        $body = $this->withToken($device)
            ->postJson('/v1/checkin/scan', [
                'token' => $token,
                'event_id' => $fixture['event']->id,
            ])
            ->assertOk()
            ->json();

        $this->assertSame('valid', $body['result']);
        $this->assertSame('early', $body['ticket']['entry']['state']);
        $this->assertNotNull($body['ticket']['entry']['starts_at']);
    }

    #[Test]
    public function a_window_somebody_has_been_sold_cannot_be_removed(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        [$slot] = $this->windows($fixture, [[10, 4]]);

        $this->hold($fixture, [0], $slot->id)->assertCreated();
        $this->pay();

        $owner = $this->makeUser($fixture['tenant']);

        $this->actingAs($owner)
            ->putJson("/v1/events/{$fixture['event']->id}/entry-slots", ['slots' => []])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'entry_slot_sold');
    }

    #[Test]
    public function two_windows_that_overlap_are_refused(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $day = Carbon::parse('2026-10-01T09:00:00Z');

        // A limit only means something if the windows do not overlap: two overlapping hundreds
        // would put two hundred people in a hall that holds one.
        $this->actingAs($owner)
            ->putJson("/v1/events/{$fixture['event']->id}/entry-slots", [
                'slots' => [
                    ['starts_at' => $day->toIso8601String(), 'ends_at' => $day->copy()->addHour()->toIso8601String(), 'capacity' => 100],
                    ['starts_at' => $day->copy()->addMinutes(30)->toIso8601String(), 'ends_at' => $day->copy()->addMinutes(90)->toIso8601String(), 'capacity' => 100],
                ],
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function a_day_can_be_filled_in_one_step(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $day = Carbon::parse('2026-10-01T09:00:00Z');

        $slots = $this->actingAs($owner)
            ->postJson("/v1/events/{$fixture['event']->id}/entry-slots/generate", [
                'starts_at' => $day->toIso8601String(),
                'ends_at' => $day->copy()->addHours(2)->toIso8601String(),
                'minutes' => 30,
                'capacity' => 50,
            ])
            ->assertOk()
            ->json('data');

        $this->assertCount(4, $slots);
        $this->assertSame(50, $slots[0]['capacity']);

        // Generated, not saved: an organiser sees the timetable before anything exists.
        $this->assertSame(0, app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => EntrySlot::where('event_id', $fixture['event']->id)->count()
        ));
    }

    #[Test]
    public function the_windows_belong_to_the_account_that_made_them(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->windows($fixture, [[10, 4]]);

        $stranger = $this->makeUser($this->makeTenant('Someone Else'));

        $this->actingAs($stranger)
            ->getJson("/v1/events/{$fixture['event']->id}/entry-slots")
            ->assertNotFound();
    }

    /* ------------------------------------------------------------------------------ helpers */

    /**
     * Windows on the day of the event, given as [hour, capacity] pairs.
     *
     * @param  list<array{0: int, 1: ?int}>  $hours
     * @return list<EntrySlot>
     */
    private function windows(array $fixture, array $hours): array
    {
        return app(TenantContext::class)->runAs($fixture['tenant'], function () use ($fixture, $hours) {
            $day = $fixture['event']->starts_at->copy()->startOfDay();

            return array_map(fn (array $pair) => EntrySlot::create([
                'tenant_id' => $fixture['tenant']->id,
                'event_id' => $fixture['event']->id,
                'starts_at' => $day->copy()->addHours($pair[0]),
                'ends_at' => $day->copy()->addHours($pair[0])->addMinutes(30),
                'capacity' => $pair[1],
                'status' => 'open',
            ]), $hours);
        });
    }

    /** @param  list<int>  $seats */
    private function hold(array $fixture, array $seats, ?string $slotId = null)
    {
        return $this->postJson('http://northgate.test/_store/hold', array_filter([
            'event_public_id' => $fixture['event']->public_id,
            'seat_ids' => array_map(fn (int $i) => $fixture['seats'][$i]->id, $seats),
            'entry_slot_id' => $slotId,
        ]));
    }

    private function pay(string $name = 'Amina Farsi', string $email = 'amina@example.test'): void
    {
        $this->post('http://northgate.test/checkout', [
            'name' => $name,
            'email' => $email,
            'gateway' => 'offline',
        ])->assertRedirect();
    }

    /** A second booking in the same test needs a browser the hold limit has never seen. */
    private function newBrowser(): void
    {
        $this->app['session']->flush();
        $this->app['session']->regenerate();
    }

    /** A scanner allowed onto this event, paired the way a real one is. */
    private function pairDevice(array $fixture): string
    {
        return app(TenantContext::class)->runAs($fixture['tenant'], function () use ($fixture) {
            $code = 'pair-'.\Illuminate\Support\Str::random(12);

            $device = \App\Models\CheckinDevice::factory()->create([
                'tenant_id' => $fixture['tenant']->id,
                'status' => 'pending',
                'pairing_code_hash' => hash('sha256', $code),
                'pairing_expires_at' => now()->addMinutes(30),
            ]);

            $device->grantAccessTo($fixture['event']);

            return $this->postJson('/v1/checkin/auth/token', [
                'pairing_code' => $code,
                'device_name' => 'Front door',
            ])->assertOk()->json('token');
        });
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
