<?php

namespace Tests\Feature;

use App\Models\Allocation;
use App\Models\Seat;
use App\Models\TicketType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * Concessions: the same seat, sold to different people at different prices.
 *
 * The thing to guard is that a type is an adjustment to the seat's own price and never a price of
 * its own — so a repriced section reprices every concession in it — and that a browser cannot name
 * a price, a type it was not offered, or more of a type than the organiser allows.
 */
class TicketTypeTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function a_concession_is_a_share_of_the_seat_price(): void
    {
        $fixture = $this->makeSellableEvent(amount: 5000);
        $child = $this->makeType($fixture, ['name' => 'Child', 'kind' => 'percent_off', 'value' => 40]);
        $this->makeType($fixture, ['name' => 'Adult', 'kind' => 'standard', 'is_default' => true]);

        $hold = $this->hold($fixture, [0, 1], [
            $fixture['seats'][0]->id => $child->id,
        ]);

        $this->assertSame(
            5000 + 3000,
            $hold['total_amount'],
            'One adult at the seat price, one child at 60% of it.'
        );

        $lines = collect($hold['seats'] ?? []);

        $this->assertEqualsCanonicalizing(
            ['Adult', 'Child'],
            $lines->pluck('ticket_type')->all(),
            'The snapshot carries the name the buyer was shown.'
        );
    }

    #[Test]
    public function a_fixed_rate_ignores_what_the_seat_costs(): void
    {
        $fixture = $this->makeSellableEvent(amount: 5000);
        $this->makeType($fixture, ['name' => 'Full', 'kind' => 'standard', 'is_default' => true]);
        $schools = $this->makeType($fixture, ['name' => 'Schools', 'kind' => 'fixed', 'value' => 800]);

        $hold = $this->hold($fixture, [0], [$fixture['seats'][0]->id => $schools->id]);

        $this->assertSame(800, $hold['total_amount']);
    }

    #[Test]
    public function a_discount_can_never_take_a_seat_below_nothing(): void
    {
        $fixture = $this->makeSellableEvent(amount: 1000);
        $this->makeType($fixture, ['name' => 'Full', 'kind' => 'standard', 'is_default' => true]);
        $carer = $this->makeType($fixture, ['name' => 'Carer', 'kind' => 'amount_off', 'value' => 4000]);

        $hold = $this->hold($fixture, [0], [$fixture['seats'][0]->id => $carer->id]);

        // Free, not a refund of three thousand.
        $this->assertSame(0, $hold['total_amount']);
    }

    #[Test]
    public function the_price_travels_to_the_ticket_and_stays_there(): void
    {
        $fixture = $this->makeSellableEvent(amount: 4000);
        $this->makeType($fixture, ['name' => 'Adult', 'kind' => 'standard', 'is_default' => true]);
        $student = $this->makeType($fixture, [
            'name' => 'Student', 'kind' => 'percent_off', 'value' => 50,
            'proof_note' => 'Bring your student card.',
        ]);

        $hold = $this->hold($fixture, [0], [$fixture['seats'][0]->id => $student->id]);
        $this->sell($fixture, $hold['hold_token']);

        $allocation = app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => Allocation::where('event_id', $fixture['event']->id)->firstOrFail()
        );

        $this->assertSame(2000, $allocation->amount);
        $this->assertSame('Student', $allocation->ticket_type_name);

        // Renamed next season. The ticket sold this season keeps its own words.
        app(TenantContext::class)->runAs($fixture['tenant'], fn () => $student->forceFill([
            'name' => 'Under 26',
        ])->save());

        $this->assertSame('Student', $allocation->fresh()->ticket_type_name);
    }

    #[Test]
    public function a_type_from_another_event_is_refused(): void
    {
        $fixture = $this->makeSellableEvent();
        $other = $this->makeSellableEvent($fixture['tenant']);

        $this->makeType($fixture, ['name' => 'Adult', 'kind' => 'standard', 'is_default' => true]);
        $theirs = $this->makeType($other, ['name' => 'Free', 'kind' => 'fixed', 'value' => 0]);

        $this->postJson("/v1/embed/events/{$fixture['event']->public_id}/holds", [
            'seat_ids' => [$fixture['seats'][0]->id],
            'seat_types' => [$fixture['seats'][0]->id => $theirs->id],
            'session_id' => 'sess_'.uniqid(),
        ])->assertStatus(422)->assertJsonPath('error.code', 'unknown_ticket_type');

        // And nothing was held while the request was being refused.
        $this->assertSame(0, app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => \App\Models\HoldItem::where('event_id', $fixture['event']->id)->count()
        ));
    }

    #[Test]
    public function an_organisers_limits_are_enforced_before_a_seat_is_held(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeType($fixture, ['name' => 'Adult', 'kind' => 'standard', 'is_default' => true]);
        $child = $this->makeType($fixture, [
            'name' => 'Child', 'kind' => 'percent_off', 'value' => 50, 'max_per_order' => 2,
        ]);

        $this->postJson("/v1/embed/events/{$fixture['event']->public_id}/holds", [
            'seat_ids' => [
                $fixture['seats'][0]->id, $fixture['seats'][1]->id, $fixture['seats'][2]->id,
            ],
            'seat_types' => [
                $fixture['seats'][0]->id => $child->id,
                $fixture['seats'][1]->id => $child->id,
                $fixture['seats'][2]->id => $child->id,
            ],
            'session_id' => 'sess_'.uniqid(),
        ])->assertStatus(422)->assertJsonPath('error.code', 'ticket_type_max');
    }

    #[Test]
    public function a_hidden_type_cannot_be_bought_and_is_not_offered(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeType($fixture, ['name' => 'Adult', 'kind' => 'standard', 'is_default' => true]);
        $staff = $this->makeType($fixture, [
            'name' => 'Staff', 'kind' => 'fixed', 'value' => 0, 'status' => 'hidden',
        ]);

        $offered = $this->getJson("/v1/embed/events/{$fixture['event']->public_id}")
            ->assertOk()->json('ticket_types');

        $this->assertSame(['Adult'], array_column($offered, 'name'));

        $this->postJson("/v1/embed/events/{$fixture['event']->public_id}/holds", [
            'seat_ids' => [$fixture['seats'][0]->id],
            'seat_types' => [$fixture['seats'][0]->id => $staff->id],
            'session_id' => 'sess_'.uniqid(),
        ])->assertStatus(409)->assertJsonPath('error.code', 'ticket_type_not_on_sale');
    }

    #[Test]
    public function an_event_with_no_types_sells_exactly_as_it_did(): void
    {
        $fixture = $this->makeSellableEvent(amount: 2500);

        $hold = $this->hold($fixture, [0]);

        $this->assertSame(2500, $hold['total_amount']);
        $this->assertNull($hold['seats'][0]['ticket_type'] ?? null);
    }

    #[Test]
    public function the_organiser_saves_the_whole_list_and_keeps_one_default(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $event = $fixture['event'];

        $saved = $this->actingAs($owner)->putJson("/v1/events/{$event->id}/ticket-types", [
            'types' => [
                ['name' => 'Adult', 'kind' => 'standard', 'is_default' => true],
                ['name' => 'Child', 'kind' => 'percent_off', 'value' => 50, 'is_default' => true],
            ],
        ])->assertOk()->json('data');

        // Two defaults were asked for; the first one wins and the database is never asked to hold
        // both, which its partial unique index would refuse.
        $this->assertSame([true, false], array_column($saved, 'is_default'));

        // Sold, and then left out of the next save.
        $child = $saved[1];
        $hold = $this->hold($fixture, [0], [$fixture['seats'][0]->id => $child['id']]);
        $this->sell($fixture, $hold['hold_token']);

        $this->actingAs($owner)->putJson("/v1/events/{$event->id}/ticket-types", [
            'types' => [['id' => $saved[0]['id'], 'name' => 'Adult', 'kind' => 'standard', 'is_default' => true]],
        ])->assertStatus(409)->assertJsonPath('error.code', 'ticket_type_sold');

        // Hiding it is the way to stop selling it, and the count says why.
        $after = $this->actingAs($owner)->getJson("/v1/events/{$event->id}/ticket-types")
            ->assertOk()->json('data');

        $this->assertSame(1, collect($after)->firstWhere('name', 'Child')['sold']);
    }

    #[Test]
    public function only_somebody_who_may_set_prices_may_change_them(): void
    {
        $fixture = $this->makeSellableEvent();
        $box = $this->makeUser($fixture['tenant'], 'box_office');

        $this->actingAs($box)->putJson("/v1/events/{$fixture['event']->id}/ticket-types", [
            'types' => [['name' => 'Free', 'kind' => 'fixed', 'value' => 0]],
        ])->assertForbidden();
    }

    /* ---------------------------------------------------------------------------- helpers */

    private function makeType(array $fixture, array $attributes): TicketType
    {
        return app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => TicketType::create($attributes + [
                'tenant_id' => $fixture['tenant']->id,
                'event_id' => $fixture['event']->id,
                'value' => 0,
            ])
        );
    }

    /** @param  list<int>  $seats */
    private function hold(array $fixture, array $seats, array $types = []): array
    {
        return $this->postJson("/v1/embed/events/{$fixture['event']->public_id}/holds", [
            'seat_ids' => array_map(fn (int $i) => $fixture['seats'][$i]->id, $seats),
            'seat_types' => $types,
            'session_id' => 'sess_'.uniqid(),
        ])->assertCreated()->json();
    }

    private function sell(array $fixture, string $holdToken): void
    {
        $api = $this->makeApiClient($fixture['tenant']);
        $reference = 'wc_'.uniqid();
        $body = json_encode(['external_order_id' => $reference, 'hold_token' => $holdToken]);

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
}
