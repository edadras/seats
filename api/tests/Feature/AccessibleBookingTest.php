<?php

namespace Tests\Feature;

use App\Domain\Access\AccessibleSeats;
use App\Domain\Availability\AvailabilityService;
use App\Domain\Inventory\HoldService;
use App\Exceptions\ApiException;
use App\Models\Seat;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * The chairs a wheelchair user and whoever comes with them actually need.
 *
 * Two claims, and both are things venues do by hand today and therefore sometimes fail to do.
 *
 * **A companion seat is never sold on its own.** Not a warning and not a convention: a basket that
 * contains the chair beside a wheelchair space, and not the space, is refused. Otherwise a stranger
 * ends up in that chair and the booking beside it is worthless.
 *
 * **Held back means held back from the public, not from the box office.** A house that keeps its
 * accessible seats off general sale and then cannot sell them to the person on the telephone has
 * held them back for nobody. And they go on sale because the hour arrived — no job, no column.
 */
class AccessibleBookingTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    /** @return array{tenant: \App\Models\Tenant, event: \App\Models\Event, seats: mixed} */
    private function night(array $event = []): array
    {
        $night = $this->makeSellableEvent(
            rows: 3,
            perRow: 5,
            // A house with a wheelchair space in it, which is the only kind this file is about.
            chart: $this->geometry(3, 5, 'stalls', accessiblePair: true),
        );

        if ([] !== $event) {
            $night['event']->forceFill($event)->save();
        }

        // Always re-read: a model straight out of create() carries only what was written, so the
        // column defaults — the per-order seat cap among them — are still null on it.
        $night['event']->refresh();

        return $night;
    }

    private function pair(array $night): array
    {
        return app(TenantContext::class)->runAs($night['tenant'], fn () => [
            'space' => Seat::where('seat_map_id', $night['map']->id)->where('accessible', true)->firstOrFail(),
            'companion' => Seat::where('seat_map_id', $night['map']->id)->where('companion', true)->firstOrFail(),
        ]);
    }

    #[Test]
    public function the_chart_says_which_chair_is_the_companion_and_publishing_keeps_it(): void
    {
        $night = $this->night();
        $pair = $this->pair($night);

        $this->assertTrue($pair['space']->accessible);
        $this->assertTrue($pair['companion']->companion);
        $this->assertFalse($pair['companion']->accessible, 'the chair beside a space is not itself a space');
        // Beside it, which is what makes the rule about rows the right rule.
        $this->assertSame($pair['space']->seat_row_id, $pair['companion']->seat_row_id);
    }

    #[Test]
    public function a_companion_seat_cannot_be_taken_on_its_own(): void
    {
        $night = $this->night();
        $pair = $this->pair($night);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night, $pair) {
            try {
                app(HoldService::class)->create($night['event'], [$pair['companion']->id], 'session-a');
                $this->fail('the chair beside a wheelchair space was sold on its own');
            } catch (ApiException $e) {
                $this->assertSame('companion_needs_accessible', $e->errorCode());
            }
        });
    }

    #[Test]
    public function it_is_taken_readily_together_with_the_space(): void
    {
        $night = $this->night();
        $pair = $this->pair($night);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night, $pair) {
            $hold = app(HoldService::class)->create(
                $night['event'],
                [$pair['space']->id, $pair['companion']->id],
                'session-b',
            );

            $this->assertCount(2, $hold->items);
        });
    }

    #[Test]
    public function an_ordinary_seat_is_unaffected(): void
    {
        $night = $this->night();

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $ordinary = Seat::where('seat_map_id', $night['map']->id)
                ->where('accessible', false)->where('companion', false)->firstOrFail();

            $hold = app(HoldService::class)->create($night['event'], [$ordinary->id], 'session-c');

            $this->assertCount(1, $hold->items);
        });
    }

    #[Test]
    public function held_back_seats_are_off_the_public_plan_and_still_at_the_counter(): void
    {
        $night = $this->night(['accessible_sale' => 'counter']);
        $pair = $this->pair($night);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night, $pair) {
            $public = collect(app(AvailabilityService::class)->forEvent($night['event']))->keyBy('seat_id');
            $counter = collect(app(AvailabilityService::class)->forEvent($night['event'], counter: true))
                ->keyBy('seat_id');

            $this->assertSame('blocked', $public[$pair['space']->id]['state']);
            $this->assertSame('blocked', $public[$pair['companion']->id]['state']);
            $this->assertSame('available', $counter[$pair['space']->id]['state']);

            // And the hold agrees with the plan, in both directions.
            try {
                app(HoldService::class)->create($night['event'], [$pair['space']->id], 'session-d');
                $this->fail('a held-back space was sold to the public');
            } catch (ApiException $e) {
                $this->assertSame('seat_unavailable', $e->errorCode());
            }

            // The window is a box-office client, which is the whole of what "the counter" means
            // here: nothing about these seats is special to it beyond that.
            $counterClient = \App\Models\ApiClient::factory()->create([
                'tenant_id' => $night['tenant']->id, 'kind' => 'box_office',
            ]);

            $hold = app(HoldService::class)->create(
                $night['event'],
                [$pair['space']->id, $pair['companion']->id],
                'session-e',
                $counterClient->id,
            );

            $this->assertCount(2, $hold->items);
        });
    }

    #[Test]
    public function they_go_on_general_sale_because_the_hour_arrived(): void
    {
        $night = $this->night([
            'accessible_sale' => 'until',
            'accessible_release_hours' => 48,
            'starts_at' => now()->addDays(10),
        ]);
        $pair = $this->pair($night);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night, $pair) {
            $before = collect(app(AvailabilityService::class)->forEvent($night['event']))->keyBy('seat_id');

            $this->assertSame('blocked', $before[$pair['space']->id]['state']);

            // Eight days on. Nothing has run, nothing has been rewritten, and the platform may as
            // well have been switched off in between.
            $this->travel(9)->days();

            $after = collect(app(AvailabilityService::class)->forEvent($night['event']->fresh()))
                ->keyBy('seat_id');

            $this->assertSame('available', $after[$pair['space']->id]['state']);
            $this->assertSame('available', $after[$pair['companion']->id]['state']);
        });
    }

    #[Test]
    public function a_screen_can_say_when_they_are_released(): void
    {
        $night = $this->night([
            'accessible_sale' => 'until',
            'accessible_release_hours' => 48,
            'starts_at' => now()->addDays(10),
        ]);

        $releases = app(AccessibleSeats::class)->releasesAt($night['event']);

        $this->assertNotNull($releases);
        $this->assertTrue($releases->equalTo($night['event']->starts_at->copy()->subHours(48)));
        // A night that never holds them back has no such date, and a screen shows nothing.
        $this->assertNull(app(AccessibleSeats::class)->releasesAt($this->night()['event']));
    }

    #[Test]
    public function four_together_never_reaches_for_the_accessible_pair(): void
    {
        $night = $this->night();
        $pair = $this->pair($night);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night, $pair) {
            $chosen = collect(app(\App\Domain\Availability\BestAvailable::class)
                ->find($night['event'], 2))
                ->pluck('seat_id');

            $this->assertFalse($chosen->contains($pair['space']->id), 'a machine does not hand out a wheelchair space');
            // And not the chair beside it either: that one cannot be held without the space, so
            // offering it would be offering a group the hold refuses for reasons nobody can see.
            $this->assertFalse($chosen->contains($pair['companion']->id));
        });
    }

    #[Test]
    public function what_a_buyer_needs_reaches_the_door_and_leaves_with_them(): void
    {
        $night = $this->night(['ask_access_needs' => true]);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $seat = \App\Models\Seat::where('seat_map_id', $night['map']->id)
                ->where('accessible', false)->where('companion', false)->firstOrFail();

            $hold = app(HoldService::class)->create($night['event'], [$seat->id], 'session-f');
            $client = $this->makeApiClient($night['tenant'])['client'];

            [$order] = app(\App\Domain\Orders\OrderService::class)->register(
                $client,
                'ORD-ACCESS-1',
                $hold->token,
                ['name' => 'Sam Buyer', 'email' => 'sam@example.test'],
            );

            $order->forceFill(['access_needs' => 'Hearing loop, please.'])->save();

            app(\App\Domain\Orders\OrderService::class)->confirm($order->fresh());

            $doors = app(\App\Domain\Checkin\DoorList::class);
            $rows = $doors->query($night['event'])->get();
            $found = collect($rows)->map(fn ($row) => $doors->present($row))
                ->firstWhere('reference', 'ORD-ACCESS-1');

            $this->assertNotNull($found, 'the booking reaches the door list');
            $this->assertSame('Hearing loop, please.', $found['access_needs']);

            // And it is theirs: a copy carries it, and asking to be forgotten takes it away.
            $copy = app(\App\Domain\Privacy\PersonalData::class)->export('sam@example.test');

            $this->assertSame(
                'Hearing loop, please.',
                collect($copy['orders'])->firstWhere('reference', 'ORD-ACCESS-1')['access_needs'],
            );

            app(\App\Domain\Privacy\PersonalData::class)->erase('sam@example.test');

            $this->assertNull($order->fresh()->access_needs);
        });
    }
}
