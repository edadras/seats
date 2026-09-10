<?php

namespace Tests\Feature;

use App\Domain\Inventory\HoldService;
use App\Domain\Orders\OrderService;
use App\Domain\Risk\Blocklist;
use App\Domain\Risk\Chargebacks;
use App\Exceptions\ApiException;
use App\Models\Allocation;
use App\Models\BlockedBuyer;
use App\Models\ExternalOrder;
use App\Models\Ticket;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * Money taken back after the tickets were sent, and the people who must not buy again.
 *
 * The claim under test is the one a venue cares about on the night: **a booking nobody paid for
 * stops working at the door and its seats go back on sale**, in one movement, without anybody
 * remembering to do either. A chargeback that only changed a status would leave a person walking in
 * on a green QR code weeks after the bank took the money back.
 *
 * The second claim is that a block is about a person and lapses on its own. Most blocks should be
 * six months rather than for ever, and a date that has to be cleared by hand is a date somebody
 * forgets.
 */
class ChargebackTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    private int $taken = 0;

    /** @return array{order: ExternalOrder, night: array} */
    private function sale(array $night, string $email = 'sam@example.test'): array
    {
        $ids = $night['seats']->slice($this->taken, 2)->pluck('id')->all();
        $this->taken += 2;

        $hold = app(HoldService::class)->create($night['event'], $ids, 'session-'.uniqid());
        $client = $this->makeApiClient($night['tenant'])['client'];
        $reference = 'ORD-'.strtoupper(uniqid());

        [$order] = app(OrderService::class)->register($client, $reference, $hold->token, [
            'name' => 'Sam Buyer', 'email' => $email, 'phone' => '+44 20 7946 0000',
        ]);

        app(OrderService::class)->confirm($order->fresh());

        return ['order' => $order->fresh(), 'night' => $night];
    }

    #[Test]
    public function the_seats_go_back_and_the_ticket_stops_working(): void
    {
        $night = $this->makeSellableEvent(rows: 2, perRow: 4);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $order = $this->sale($night)['order'];

            $this->assertSame(2, Allocation::where('external_order_row_id', $order->id)
                ->where('status', 'active')->count());

            $after = app(Chargebacks::class)->record($order, 'Cardholder disputed', fee: 1500);

            $this->assertSame('charged_back', $after->status);
            $this->assertNotNull($after->charged_back_at);
            $this->assertSame(1500, (int) $after->chargeback_fee);

            // The chair is sellable again tonight...
            $this->assertSame(0, Allocation::where('external_order_row_id', $order->id)
                ->where('status', 'active')->count());

            // ...and the door refuses the code, which is the half that matters on the night.
            $tickets = Ticket::whereIn(
                'allocation_id',
                Allocation::where('external_order_row_id', $order->id)->pluck('id'),
            )->get();

            $this->assertGreaterThan(0, $tickets->count());
            $this->assertTrue($tickets->every(fn (Ticket $ticket) => 'void' === $ticket->status));
        });
    }

    #[Test]
    public function the_same_dispute_reported_twice_is_one_chargeback(): void
    {
        $night = $this->makeSellableEvent(rows: 2, perRow: 4);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $order = $this->sale($night)['order'];

            $first = app(Chargebacks::class)->record($order, 'Disputed', fee: 1500);
            $when = $first->charged_back_at;

            // A gateway webhook and somebody typing it in. The second must not release seats that
            // have since been sold to somebody else.
            $second = app(Chargebacks::class)->record($order->fresh(), 'Disputed again', fee: 9900);

            $this->assertTrue($when->equalTo($second->charged_back_at));
            $this->assertSame(1500, (int) $second->chargeback_fee);
        });
    }

    #[Test]
    public function a_booking_nobody_ever_paid_for_cannot_be_charged_back(): void
    {
        $night = $this->makeSellableEvent(rows: 2, perRow: 4);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $ids = $night['seats']->take(1)->pluck('id')->all();
            $hold = app(HoldService::class)->create($night['event'], $ids, 'session-pending');
            $client = $this->makeApiClient($night['tenant'])['client'];

            [$order] = app(OrderService::class)->register($client, 'ORD-PENDING', $hold->token, [
                'name' => 'Sam', 'email' => 'pending@example.test',
            ]);

            $this->expectException(ApiException::class);

            app(Chargebacks::class)->record($order, 'Disputed');
        });
    }

    #[Test]
    public function it_is_told_apart_from_a_refund_in_the_takings(): void
    {
        $night = $this->makeSellableEvent(rows: 3, perRow: 4);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $given = $this->sale($night, 'refunded@example.test')['order'];
            $taken = $this->sale($night, 'disputed@example.test')['order'];

            app(OrderService::class)->refund($given);
            app(Chargebacks::class)->record($taken, 'Disputed', fee: 1500);

            $tally = app(Chargebacks::class)->tallyFor(ExternalOrder::all());

            // One of them, not both: an organiser reading a settlement has to be able to tell what
            // they chose to give back from what was taken off them.
            $this->assertSame(1, $tally['count']);
            $this->assertSame(1500, $tally['fees']);
            $this->assertSame('refunded', $given->fresh()->status);
        });
    }

    #[Test]
    public function blocking_is_a_choice_rather_than_a_consequence(): void
    {
        $night = $this->makeSellableEvent(rows: 3, perRow: 4);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $quiet = $this->sale($night, 'unlucky@example.test')['order'];
            app(Chargebacks::class)->record($quiet, 'Train cancelled, could not reach anybody');

            // Not barred: a disputed payment is sometimes a stolen card and sometimes somebody
            // who could not reach a human being.
            $this->assertFalse(app(Blocklist::class)->blocks('unlucky@example.test'));

            $bad = $this->sale($night, 'fraud@example.test')['order'];
            app(Chargebacks::class)->record($bad, 'Third dispute this month', block: true);

            $this->assertTrue(app(Blocklist::class)->blocks('fraud@example.test'));
        });
    }

    #[Test]
    public function a_blocked_buyer_is_refused_before_the_money_and_told_to_ring(): void
    {
        $night = $this->makeSellableEvent(rows: 2, perRow: 4);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            app(Blocklist::class)->add('barred@example.test', null, 'Repeated chargebacks');

            $ids = $night['seats']->take(1)->pluck('id')->all();
            $hold = app(HoldService::class)->create($night['event'], $ids, 'session-barred');
            $client = $this->makeApiClient($night['tenant'])['client'];

            try {
                app(OrderService::class)->register($client, 'ORD-BARRED', $hold->token, [
                    'name' => 'Barred', 'email' => 'barred@example.test',
                ]);
                $this->fail('a barred buyer registered an order');
            } catch (ApiException $e) {
                $this->assertSame('buyer_blocked', $e->errorCode());
                // The reason stays inside: it is somebody's note about a person, not a message.
                $this->assertStringNotContainsString('Repeated', $e->localisedMessage());
                $this->assertStringContainsString('box office', $e->localisedMessage());
            }
        });
    }

    #[Test]
    public function a_telephone_number_blocks_as_well_as_an_address(): void
    {
        $night = $this->makeSellableEvent(rows: 2, perRow: 4);

        app(TenantContext::class)->runAs($night['tenant'], function () {
            app(Blocklist::class)->add(null, '+44 20 7946 0000', 'Barring order');

            $this->assertTrue(app(Blocklist::class)->blocks('brand-new@example.test', '+44 20 7946 0000'));
            $this->assertFalse(app(Blocklist::class)->blocks('brand-new@example.test', '+44 20 7946 1111'));
        });
    }

    #[Test]
    public function a_block_with_a_date_on_it_lapses_without_anybody_acting(): void
    {
        $night = $this->makeSellableEvent(rows: 2, perRow: 4);

        app(TenantContext::class)->runAs($night['tenant'], function () {
            app(Blocklist::class)->add('temporary@example.test', null, 'Six months', now()->addMonths(6));

            $this->assertTrue(app(Blocklist::class)->blocks('temporary@example.test'));

            $this->travel(7)->months();

            $this->assertFalse(app(Blocklist::class)->blocks('temporary@example.test'));
            // Left in the table on purpose: "this happened once" is worth reading when it happens
            // a second time.
            $this->assertNotNull(BlockedBuyer::where('email', 'temporary@example.test')->first());
        });
    }

    #[Test]
    public function blocking_the_same_person_twice_moves_the_date_rather_than_adding_a_row(): void
    {
        $night = $this->makeSellableEvent(rows: 2, perRow: 4);

        app(TenantContext::class)->runAs($night['tenant'], function () {
            app(Blocklist::class)->add('repeat@example.test', null, 'First time', now()->addMonth());
            app(Blocklist::class)->add('repeat@example.test', null, 'Second time', null);

            $blocks = BlockedBuyer::where('email', 'repeat@example.test')->get();

            $this->assertCount(1, $blocks, 'one person is one answer to whether they may buy');
            $this->assertNull($blocks->first()->until);
            $this->assertSame('Second time', $blocks->first()->reason);
        });
    }

    #[Test]
    public function a_block_needs_somebody_to_be_about(): void
    {
        $night = $this->makeSellableEvent(rows: 2, perRow: 4);

        app(TenantContext::class)->runAs($night['tenant'], function () {
            $this->expectException(ApiException::class);

            app(Blocklist::class)->add(null, null, 'Nobody in particular');
        });
    }

    #[Test]
    public function the_management_api_records_one_and_keeps_the_list(): void
    {
        $night = $this->makeSellableEvent(rows: 2, perRow: 4);
        $user = $this->makeUser($night['tenant']);
        $headers = ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];

        $order = app(TenantContext::class)->runAs(
            $night['tenant'],
            fn () => $this->sale($night, 'disputed@example.test')['order'],
        );

        $this->withHeaders($headers)
            ->postJson("/v1/orders/{$order->id}/chargeback", [
                'reason' => 'Cardholder disputed', 'fee' => 1500, 'block' => true,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'charged_back')
            ->assertJsonPath('chargeback_fee', 1500)
            ->assertJsonPath('seats_released', 2);

        $this->withHeaders($headers)->getJson('/v1/blocked-buyers')
            ->assertOk()
            ->assertJsonPath('data.0.email', 'disputed@example.test')
            ->assertJsonPath('data.0.in_force', true);

        $id = $this->withHeaders($headers)->getJson('/v1/blocked-buyers')->json('data.0.id');

        $this->withHeaders($headers)->deleteJson("/v1/blocked-buyers/{$id}")->assertNoContent();

        $this->withHeaders($headers)->getJson('/v1/blocked-buyers')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
