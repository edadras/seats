<?php

namespace Tests\Feature;

use App\Models\Allocation;
use App\Models\EventSeatOverride;
use App\Models\Hold;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ActsAsStorefront;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * The order state machine as the storefront actually drives it — including the retries and
 * duplicate hooks a real WooCommerce install produces.
 */
class OrderLifecycleTest extends TestCase
{
    use ActsAsStorefront, BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function confirming_twice_produces_one_allocation_and_one_ticket(): void
    {
        $ctx = $this->sellableOrder('wc_2001');

        $first = $this->storefront('POST', '/v1/integrations/woocommerce/orders/wc_2001/confirm')->assertOk();
        $second = $this->storefront('POST', '/v1/integrations/woocommerce/orders/wc_2001/confirm')->assertOk();

        $this->assertSame('confirmed', $first->json('status'));
        $this->assertSame('confirmed', $second->json('status'));

        $this->assertSame(
            array_column($first->json('allocations'), 'id'),
            array_column($second->json('allocations'), 'id'),
            'A repeated confirm must return the same allocations, not new ones.',
        );

        $this->asTenant($ctx['tenant'], function () {
            $this->assertSame(2, Allocation::where('status', 'active')->count());
            $this->assertSame(2, Ticket::count());
        });

        // The token is minted once. The second confirm legitimately has none to hand back.
        $this->assertNotNull($first->json('tickets.0.token'));
        $this->assertNull($second->json('tickets.0.token'));
    }

    #[Test]
    public function retrying_confirm_with_the_same_idempotency_key_replays_the_stored_response(): void
    {
        $this->sellableOrder('wc_2002');

        $key = 'confirm-attempt-1';

        $first = $this->storefront('POST', '/v1/integrations/woocommerce/orders/wc_2002/confirm', [], $key)->assertOk();
        $second = $this->storefront('POST', '/v1/integrations/woocommerce/orders/wc_2002/confirm', [], $key)->assertOk();

        $second->assertHeader('Idempotent-Replay', 'true');

        // The same response content — including the ticket token, because the caller may never
        // have received the first one. That is the entire point of the replay store.
        //
        // assertEquals, not assertSame: the replay is re-encoded from stored JSON, so key order
        // can differ while the payload is identical.
        $this->assertEquals($first->json(), $second->json());
        $this->assertSame($first->json('tickets.0.token'), $second->json('tickets.0.token'));
        $this->assertNotNull($second->json('tickets.0.token'));
    }

    #[Test]
    public function a_hold_that_expired_before_payment_cannot_be_confirmed(): void
    {
        $ctx = $this->sellableOrder('wc_2003');

        $this->asTenant($ctx['tenant'], fn () => Hold::query()->update(['expires_at' => now()->subMinute()]));

        $this->storefront('POST', '/v1/integrations/woocommerce/orders/wc_2003/confirm')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'hold_expired');

        $this->asTenant($ctx['tenant'], function () {
            $this->assertSame(0, Allocation::count(), 'No seat may be allocated from an expired hold.');
            $this->assertSame(0, Ticket::count());
        });
    }

    #[Test]
    public function cancelling_a_pending_order_returns_the_seats_to_sale(): void
    {
        $ctx = $this->sellableOrder('wc_2004');
        $seatId = $ctx['seats'][0]->id;

        $this->storefront('POST', '/v1/integrations/woocommerce/orders/wc_2004/cancel', ['reason' => 'failed'])
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');

        $states = collect($this->getJson("/v1/embed/events/{$ctx['event']->public_id}/availability")->json('seats'))
            ->keyBy('seat_id');

        $this->assertSame('available', $states[$seatId]['state']);
    }

    #[Test]
    public function cancelling_is_idempotent(): void
    {
        $this->sellableOrder('wc_2005');

        $this->storefront('POST', '/v1/integrations/woocommerce/orders/wc_2005/cancel')->assertOk();
        $this->storefront('POST', '/v1/integrations/woocommerce/orders/wc_2005/cancel')
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');
    }

    #[Test]
    public function a_full_refund_voids_the_tickets_and_frees_the_seats(): void
    {
        $ctx = $this->sellableOrder('wc_2006');

        $this->storefront('POST', '/v1/integrations/woocommerce/orders/wc_2006/confirm')->assertOk();
        $this->storefront('POST', '/v1/integrations/woocommerce/orders/wc_2006/refund', ['reason' => 'customer request'])
            ->assertOk()
            ->assertJsonPath('status', 'refunded');

        $this->asTenant($ctx['tenant'], function () {
            $this->assertSame(0, Allocation::where('status', 'active')->count());
            $this->assertSame(2, Ticket::where('status', 'void')->count());
        });

        $states = collect($this->getJson("/v1/embed/events/{$ctx['event']->public_id}/availability")->json('seats'))
            ->keyBy('seat_id');

        // Default policy is `release`, so the seats go straight back on sale.
        $this->assertSame('available', $states[$ctx['seats'][0]->id]['state']);
    }

    #[Test]
    public function a_partial_refund_leaves_the_remaining_seat_sold(): void
    {
        $ctx = $this->sellableOrder('wc_2007');
        $this->storefront('POST', '/v1/integrations/woocommerce/orders/wc_2007/confirm')->assertOk();

        $this->storefront('POST', '/v1/integrations/woocommerce/orders/wc_2007/refund', [
            'seat_ids' => [$ctx['seats'][0]->id],
        ])->assertOk()->assertJsonPath('status', 'partially_refunded');

        $states = collect($this->getJson("/v1/embed/events/{$ctx['event']->public_id}/availability")->json('seats'))
            ->keyBy('seat_id');

        $this->assertSame('available', $states[$ctx['seats'][0]->id]['state']);
        $this->assertSame('allocated', $states[$ctx['seats'][1]->id]['state']);
    }

    #[Test]
    public function the_hold_back_policy_keeps_a_refunded_seat_off_sale(): void
    {
        $ctx = $this->sellableOrder('wc_2008');
        $this->asTenant($ctx['tenant'], fn () => $ctx['event']->update(['refund_policy' => 'hold_back']));

        $this->storefront('POST', '/v1/integrations/woocommerce/orders/wc_2008/confirm')->assertOk();
        $this->storefront('POST', '/v1/integrations/woocommerce/orders/wc_2008/refund')->assertOk();

        $states = collect($this->getJson("/v1/embed/events/{$ctx['event']->public_id}/availability")->json('seats'))
            ->keyBy('seat_id');

        $this->assertSame('blocked', $states[$ctx['seats'][0]->id]['state']);

        $this->asTenant($ctx['tenant'], fn () => $this->assertSame(
            2, EventSeatOverride::where('blocked', true)->count()
        ));
    }

    #[Test]
    public function a_refunded_seat_released_back_to_sale_can_be_sold_again(): void
    {
        // The end-to-end check that a refund really does return inventory: the partial unique
        // index must not still be occupied by the old allocation or the hold that produced it.
        $ctx = $this->sellableOrder('wc_2009');

        $this->storefront('POST', '/v1/integrations/woocommerce/orders/wc_2009/confirm')->assertOk();
        $this->storefront('POST', '/v1/integrations/woocommerce/orders/wc_2009/refund')->assertOk();

        $this->postJson("/v1/embed/events/{$ctx['event']->public_id}/holds", [
            'seat_ids' => [$ctx['seats'][0]->id],
            'session_id' => 'second-buyer',
        ])->assertCreated();
    }

    #[Test]
    public function confirming_an_order_that_was_already_refunded_does_not_resurrect_it(): void
    {
        $this->sellableOrder('wc_2010');

        $this->storefront('POST', '/v1/integrations/woocommerce/orders/wc_2010/confirm')->assertOk();
        $this->storefront('POST', '/v1/integrations/woocommerce/orders/wc_2010/refund')->assertOk();

        $again = $this->storefront('POST', '/v1/integrations/woocommerce/orders/wc_2010/confirm')->assertOk();

        $this->assertSame('refunded', $again->json('status'));
        $this->assertSame([], array_filter(
            $again->json('allocations'),
            fn ($a) => $a['status'] === 'active',
        ));
    }

    #[Test]
    public function registering_the_same_order_twice_binds_only_one_hold(): void
    {
        $this->sellableOrder('wc_2011');

        // WooCommerce firing its "order created" hook twice must not create a second order row.
        $repeat = $this->storefront('POST', '/v1/integrations/woocommerce/orders', [
            'external_order_id' => 'wc_2011',
            'hold_token' => $this->holdToken,
        ])->assertOk();

        $this->assertSame('pending', $repeat->json('status'));
        $this->assertSame(1, \App\Models\ExternalOrder::withoutGlobalScopes()->count());
    }
}
