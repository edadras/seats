<?php

namespace Tests\Feature;

use App\Domain\Sites\SiteProvisioner;
use App\Models\DiscountCode;
use App\Models\ExternalOrder;
use App\Models\Seat;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * Discount codes, on a hosted site and in the panel.
 *
 * The checks that matter are the ones about money: that the amount charged is the amount shown,
 * that a code cannot be stretched past what the organiser allowed, and that nothing about the
 * discount comes from the browser — a buyer who posts `amount` at the checkout must be ignored,
 * because the only thing they are trusted to send is the spelling of a code.
 */
class DiscountCodeTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function a_percentage_code_takes_money_off_what_is_charged(): void
    {
        $fixture = $this->makeSellableEvent(amount: 5000);
        $this->makeSite($fixture['tenant']);
        $this->makeCode($fixture['tenant'], ['code' => 'HALF', 'kind' => 'percent', 'value' => 50]);

        $this->hold($fixture, 1);

        $this->post('http://northgate.test/checkout/discount', ['code' => 'half'])
            ->assertRedirect('/checkout');

        // The summary says it before the buyer commits to it.
        $this->get('http://northgate.test/checkout')->assertOk()->assertSee('HALF');

        $this->buy();

        $order = $this->order($fixture['tenant']);

        $this->assertSame(2500, $order->total_amount, 'Half of one €50 seat.');
        $this->assertSame('HALF', $order->metadata['discount']['code']);
        $this->assertSame(5000, $order->metadata['discount']['subtotal'], 'What it would have cost.');
        $this->assertSame(1, $this->code($fixture['tenant'], 'HALF')->used_count);
    }

    #[Test]
    public function a_fixed_code_is_never_worth_more_than_the_booking(): void
    {
        $fixture = $this->makeSellableEvent(amount: 1200);
        $this->makeSite($fixture['tenant']);
        $this->makeCode($fixture['tenant'], [
            'code' => 'TWENTY', 'kind' => 'fixed', 'value' => 2000, 'currency' => 'EUR',
        ]);

        $this->hold($fixture, 1);
        $this->post('http://northgate.test/checkout/discount', ['code' => 'TWENTY']);
        $this->buy();

        // A €20 code against a €12 booking is a free ticket, not €8 back.
        $this->assertSame(0, $this->order($fixture['tenant'])->total_amount);
    }

    #[Test]
    public function a_code_can_only_be_used_as_often_as_it_says(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->makeCode($fixture['tenant'], [
            'code' => 'ONCE', 'kind' => 'percent', 'value' => 10, 'max_uses' => 1,
        ]);

        $this->hold($fixture, 1);
        $this->post('http://northgate.test/checkout/discount', ['code' => 'ONCE']);
        $this->buy();

        $this->assertSame(2250, $this->order($fixture['tenant'])->total_amount);

        // A second buyer, in their own session.
        $this->flushSession();
        $this->hold($fixture, 1, from: 1);

        $this->post('http://northgate.test/checkout/discount', ['code' => 'ONCE'])
            ->assertRedirect('/checkout')
            ->assertSessionHas('seatmap_discount_error', 'used_up');

        $this->get('http://northgate.test/checkout')->assertOk()->assertSee('used up', escape: false);

        $this->buy();

        $orders = $this->orders($fixture['tenant']);

        $this->assertSame([2250, 2500], $orders->pluck('total_amount')->sort()->values()->all(),
            'The second buyer paid the full price, and was told why.');
        $this->assertSame(1, $this->code($fixture['tenant'], 'ONCE')->used_count);
    }

    #[Test]
    public function a_code_for_another_event_is_refused(): void
    {
        $fixture = $this->makeSellableEvent();
        $other = $this->makeSellableEvent($fixture['tenant']);
        $this->makeSite($fixture['tenant']);

        $this->makeCode($fixture['tenant'], [
            'code' => 'GALA', 'kind' => 'percent', 'value' => 25, 'event_id' => $other['event']->id,
        ]);

        $this->hold($fixture, 1);

        $this->post('http://northgate.test/checkout/discount', ['code' => 'GALA'])
            ->assertSessionHas('seatmap_discount_error', 'wrong_event');

        $this->buy();

        $this->assertSame(2500, $this->order($fixture['tenant'])->total_amount);
    }

    #[Test]
    public function a_code_belongs_to_the_account_that_made_it(): void
    {
        $mine = $this->makeSellableEvent($this->makeTenant('Northgate'));
        $theirs = $this->makeSellableEvent($this->makeTenant('Southbank'));

        $this->makeSite($mine['tenant']);
        $this->makeCode($theirs['tenant'], ['code' => 'SHARED', 'kind' => 'percent', 'value' => 90]);

        $this->hold($mine, 1);

        // Same spelling, another organiser's table. It resolves to nothing here.
        $this->post('http://northgate.test/checkout/discount', ['code' => 'SHARED'])
            ->assertSessionHas('seatmap_discount_error', 'unknown');
    }

    #[Test]
    public function a_code_that_needs_four_tickets_refuses_one(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->makeCode($fixture['tenant'], [
            'code' => 'GROUP', 'kind' => 'percent', 'value' => 20, 'min_seats' => 4,
        ]);

        $this->hold($fixture, 1);

        $this->post('http://northgate.test/checkout/discount', ['code' => 'GROUP'])
            ->assertSessionHas('seatmap_discount_error', 'too_few_seats');

        $this->flushSession();
        $this->hold($fixture, 4, from: 5);

        $this->post('http://northgate.test/checkout/discount', ['code' => 'GROUP'])
            ->assertSessionMissing('seatmap_discount_error');
        $this->buy();

        $this->assertSame(8000, $this->order($fixture['tenant'])->total_amount, 'Four seats, a fifth off.');
    }

    #[Test]
    public function an_expired_code_stops_working_without_anybody_touching_it(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->makeCode($fixture['tenant'], [
            'code' => 'LATE', 'kind' => 'percent', 'value' => 50,
            'ends_at' => now()->addMinutes(2),
        ]);

        $this->hold($fixture, 1);
        $this->post('http://northgate.test/checkout/discount', ['code' => 'LATE']);

        // Past the code's own end, and well inside the hold's — the seats are still theirs.
        $this->travel(3)->minutes();

        // Re-priced on the way in, not remembered from when it was typed.
        $this->get('http://northgate.test/checkout')->assertOk()->assertDontSee('LATE');

        $this->buy();

        $this->assertSame(2500, $this->order($fixture['tenant'])->total_amount);
    }

    #[Test]
    public function an_organiser_creates_a_code_and_cannot_delete_a_used_one(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $owner = $this->makeUser($fixture['tenant']);

        $created = $this->actingAs($owner)->postJson('/v1/discounts', [
            'code' => ' spring24 ',
            'description' => 'The mailing list',
            'kind' => 'percent',
            'value' => 15,
        ])->assertCreated()->json();

        $this->assertSame('SPRING24', $created['code'], 'Normalised on the way in.');
        $this->assertTrue($created['live']);

        // A percentage over 100 is a payment to the buyer.
        $this->actingAs($owner)->postJson('/v1/discounts', [
            'code' => 'TOOMUCH', 'kind' => 'percent', 'value' => 150,
        ])->assertStatus(422);

        // A fixed amount with no currency matches every currency, which cannot be right.
        $this->actingAs($owner)->postJson('/v1/discounts', [
            'code' => 'NOCUR', 'kind' => 'fixed', 'value' => 500,
        ])->assertStatus(422);

        $this->hold($fixture, 1);
        $this->post('http://northgate.test/checkout/discount', ['code' => 'SPRING24']);
        $this->buy();

        $shown = $this->actingAs($owner)->getJson('/v1/discounts/'.$created['id'])->assertOk()->json();

        $this->assertSame(1, $shown['used_count']);
        $this->assertSame(375, $shown['redemptions'][0]['amount'], '15% of one €25 seat.');

        // Deleting it would leave that order pointing at a reason that no longer exists.
        $this->actingAs($owner)->deleteJson('/v1/discounts/'.$created['id'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'discount_in_use');

        $this->actingAs($owner)->patchJson('/v1/discounts/'.$created['id'], ['status' => 'paused'])
            ->assertOk()
            ->assertJsonPath('live', false);
    }

    #[Test]
    public function only_somebody_who_may_set_prices_may_write_a_code(): void
    {
        $fixture = $this->makeSellableEvent();
        $box = $this->makeUser($fixture['tenant'], 'box_office');

        // The box office refunds; it does not invent half-price tickets.
        $this->actingAs($box)->getJson('/v1/discounts')->assertForbidden();
        $this->actingAs($box)->postJson('/v1/discounts', [
            'code' => 'MATE', 'kind' => 'percent', 'value' => 100,
        ])->assertForbidden();
    }

    /* ------------------------------------------------------------------------------ helpers */

    private function makeCode($tenant, array $attributes): DiscountCode
    {
        return app(TenantContext::class)->runAs($tenant, fn () => DiscountCode::create($attributes + [
            'tenant_id' => $tenant->id,
            'status' => 'active',
        ]));
    }

    private function code($tenant, string $code): DiscountCode
    {
        return app(TenantContext::class)->runAs(
            $tenant,
            fn () => DiscountCode::where('code', $code)->firstOrFail()
        );
    }

    private function order($tenant): ExternalOrder
    {
        return $this->orders($tenant)->sortByDesc('created_at')->first();
    }

    private function orders($tenant)
    {
        return app(TenantContext::class)->runAs($tenant, fn () => ExternalOrder::get());
    }

    /** Put some seats in this browser's basket, the way the picker does. */
    private function hold(array $fixture, int $seats, int $from = 0): void
    {
        $ids = array_map(
            fn (int $index) => $fixture['seats'][$index]->id,
            range($from, $from + $seats - 1)
        );

        $this->postJson('http://northgate.test/_store/hold', [
            'event_public_id' => $fixture['event']->public_id,
            'seat_ids' => $ids,
        ])->assertCreated();
    }

    private function buy(): void
    {
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
