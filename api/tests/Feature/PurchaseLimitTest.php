<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\ExternalOrder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * Four at a time, six times over, is twenty-four.
 *
 * `max_seats_per_order` has existed since the beginning and stops nothing on its own. These tests
 * pin the limit that does — counted across everything one address already holds — along with the
 * two cheap questions a script gets wrong and a person never notices.
 *
 * The most important test here is the one about *where* the limit is checked: before the money,
 * never after it. A booking refused at confirmation is money taken for tickets nobody has.
 */
class PurchaseLimitTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    /* ------------------------------------------------------------------- how many one may have */

    #[Test]
    public function a_limit_is_counted_across_everything_that_person_already_has(): void
    {
        $fixture = $this->makeSellableEvent(rows: 4, perRow: 8);
        $this->makeSite($fixture['tenant']);
        $this->limit($fixture, 2);

        // Two, which is the lot.
        $this->buy($fixture, [0, 1], 'dana@example.test')->assertRedirect();

        // A second visit, a second basket, the same person: refused, and told how many are left.
        $this->newBrowser();
        $refused = $this->buy($fixture, [2], 'dana@example.test');

        $refused->assertRedirect('/checkout');
        $this->assertStringContainsString('limited to 2 per person', $this->flash());

        // Somebody else is not this person.
        $this->newBrowser();
        $this->buy($fixture, [3], 'amir@example.test')->assertRedirect();

        $this->inTenant($fixture, fn () => $this->assertSame(2, ExternalOrder::count()));
    }

    #[Test]
    public function a_refunded_ticket_is_a_ticket_that_person_no_longer_has(): void
    {
        $fixture = $this->makeSellableEvent(rows: 4, perRow: 8);
        $this->makeSite($fixture['tenant']);
        $this->limit($fixture, 1);

        $this->buy($fixture, [0], 'dana@example.test')->assertRedirect();

        $order = $this->inTenant($fixture, fn () => ExternalOrder::firstOrFail());

        $this->actingAs($this->makeUser($fixture['tenant']))
            ->postJson('/v1/orders/'.$order->id.'/refund', ['reason' => 'changed their mind'])
            ->assertOk();

        // A limit that remembered a cancelled booking would tell somebody who gave their ticket
        // back last week that they may not come at all.
        $this->newBrowser();
        $this->buy($fixture, [1], 'dana@example.test')->assertRedirect();

        $this->inTenant($fixture, fn () => $this->assertSame(2, ExternalOrder::count()));
    }

    #[Test]
    public function no_limit_is_the_ordinary_case(): void
    {
        $fixture = $this->makeSellableEvent(rows: 4, perRow: 8);
        $this->makeSite($fixture['tenant']);

        $this->buy($fixture, [0, 1, 2], 'dana@example.test')->assertRedirect();
        $this->newBrowser();
        $this->buy($fixture, [3, 4, 5], 'dana@example.test')->assertRedirect();

        $this->inTenant($fixture, fn () => $this->assertSame(2, ExternalOrder::count()));
    }

    #[Test]
    public function the_window_is_not_a_website(): void
    {
        $fixture = $this->makeSellableEvent(rows: 4, perRow: 8);
        $this->limit($fixture, 1);
        $user = $this->makeUser($fixture['tenant']);

        $sell = fn (int $seat) => $this->actingAs($user)
            ->postJson('/v1/events/'.$fixture['event']->id.'/sell', [
                'seat_ids' => [$fixture['seats'][$seat]->id],
                'buyer' => ['name' => 'Dana', 'email' => 'dana@example.test'],
                'payment' => 'paid',
            ]);

        $sell(0)->assertCreated();

        // The person is standing in front of the clerk. An organiser who wants to say no to them
        // can say no to them.
        $sell(1)->assertCreated();
    }

    #[Test]
    public function a_limit_is_checked_before_the_money_and_never_after_it(): void
    {
        $fixture = $this->makeSellableEvent(rows: 4, perRow: 8);
        $this->limit($fixture, 1);
        $api = $this->makeApiClient($fixture['tenant']);

        $first = $this->registerThroughShop($fixture, $api, [0], 'dana@example.test');
        $this->confirmThroughShop($fixture, $api, $first)->assertOk();

        // A shop registers its order before it charges, so the limit is applied there.
        $this->registerThroughShop($fixture, $api, [1], 'dana@example.test', expect: 409);

        /*
         * And a shop that says nothing about the buyer until it confirms is not held to the limit
         * at all — because the only place left to refuse is after the gateway has settled, and one
         * ticket over a limit is a smaller wrong than money taken for a booking that does not
         * exist. The honest consequence, written down rather than papered over.
         */
        $late = $this->registerThroughShop($fixture, $api, [2], null);
        $this->confirmThroughShop($fixture, $api, $late, 'dana@example.test')->assertOk();
    }

    /* --------------------------------------------------------------------------- the two asks */

    #[Test]
    public function a_field_that_is_not_there_is_a_field_only_a_script_fills(): void
    {
        $fixture = $this->makeSellableEvent(rows: 4, perRow: 8);
        $this->makeSite($fixture['tenant']);

        $this->buy($fixture, [0], 'dana@example.test', ['website' => 'https://buy-cheap.example'])
            ->assertRedirect('/checkout');

        $this->assertStringContainsString('could not be taken', $this->flash());
        $this->inTenant($fixture, fn () => $this->assertSame(0, ExternalOrder::count()));
    }

    #[Test]
    public function a_form_sent_faster_than_a_person_can_fill_one_in_is_refused(): void
    {
        $fixture = $this->makeSellableEvent(rows: 4, perRow: 8);
        $this->makeSite($fixture['tenant']);

        $this->inTenant($fixture, fn () => Event::whereKey($fixture['event']->id)
            ->update(['checkout_min_seconds' => 30]));

        // Straight to the post, with no memory of the page ever being drawn: which is what a
        // request that never opened it looks like.
        $this->buy($fixture, [0], 'dana@example.test', [], visit: false)
            ->assertRedirect('/checkout');

        $this->assertStringContainsString('before the form was filled in', $this->flash());

        // The same buyer, having actually sat with the page for long enough. Different seats:
        // the refused attempt left the first one held, which is the correct outcome of a refusal
        // that happens before anything is charged.
        $this->newBrowser();
        $this->hold($fixture, [4]);
        $this->get('http://northgate.test/checkout')->assertOk();
        $this->travel(31)->seconds();

        $this->post('http://northgate.test/checkout', [
            'name' => 'Dana', 'email' => 'dana@example.test', 'gateway' => 'offline',
        ])->assertRedirect();
    }

    #[Test]
    public function the_question_is_not_asked_at_all_by_default(): void
    {
        $fixture = $this->makeSellableEvent(rows: 4, perRow: 8);
        $this->makeSite($fixture['tenant']);

        // Zero seconds is the default, and a checkout that refused a fast typist by default would
        // be a checkout that lost bookings for nothing.
        $this->buy($fixture, [0], 'dana@example.test', [], visit: false)->assertRedirect();
        $this->inTenant($fixture, fn () => $this->assertSame(1, ExternalOrder::count()));
    }

    /* ------------------------------------------------------------------------------ helpers */

    private function limit(array $fixture, int $each): void
    {
        $this->inTenant($fixture, fn () => Event::whereKey($fixture['event']->id)
            ->update(['max_per_buyer' => $each]));
    }

    /** Hold some seats and pay for them on the hosted site, the way a buyer does. */
    private function buy(array $fixture, array $seats, string $email, array $extra = [], bool $visit = true)
    {
        $this->hold($fixture, $seats);

        if ($visit) {
            $this->get('http://northgate.test/checkout')->assertOk();
        }

        return $this->post('http://northgate.test/checkout', $extra + [
            'name' => 'A buyer',
            'email' => $email,
            'gateway' => 'offline',
        ]);
    }

    private function hold(array $fixture, array $seats): void
    {
        $this->postJson('http://northgate.test/_store/hold', [
            'event_public_id' => $fixture['event']->public_id,
            'seat_ids' => array_map(fn (int $i) => $fixture['seats'][$i]->id, $seats),
        ])->assertCreated();
    }

    private function flash(): string
    {
        return (string) session('seatmap_checkout_error');
    }

    /** @param  list<int>  $seats */
    private function registerThroughShop(array $fixture, array $api, array $seats, ?string $email, int $expect = 201): string
    {
        $hold = $this->postJson("/v1/embed/events/{$fixture['event']->public_id}/holds", [
            'seat_ids' => array_map(fn (int $i) => $fixture['seats'][$i]->id, $seats),
            'session_id' => 'sess_'.\Illuminate\Support\Str::random(8),
        ])->assertCreated()->json();

        $reference = 'wc_'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(10));
        $body = json_encode(array_filter([
            'external_order_id' => $reference,
            'hold_token' => $hold['hold_token'],
            'buyer' => $email ? ['name' => 'Dana', 'email' => $email] : null,
        ]));

        $this->call(
            'POST', '/v1/integrations/woocommerce/orders', [], [], [],
            $this->serverHeaders($this->signedHeaders(
                $api['key_id'], $api['secret'], 'POST', '/v1/integrations/woocommerce/orders', $body
            )),
            $body,
        )->assertStatus($expect);

        return $reference;
    }

    private function confirmThroughShop(array $fixture, array $api, string $reference, ?string $email = null)
    {
        $path = '/v1/integrations/woocommerce/orders/'.$reference.'/confirm';
        $payload = json_encode($email
            ? ['buyer' => ['name' => 'Dana', 'email' => $email]]
            : []);

        return $this->call(
            'POST', $path, [], [], [],
            $this->serverHeaders($this->signedHeaders($api['key_id'], $api['secret'], 'POST', $path, $payload)),
            $payload,
        );
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

    private function makeSite($tenant): \App\Models\Site
    {
        return app(TenantContext::class)->runAs($tenant, function () use ($tenant) {
            $site = app(\App\Domain\Sites\SiteProvisioner::class)->create($tenant->name);

            \App\Models\SiteDomain::create([
                'site_id' => $site->id,
                'hostname' => 'northgate.test',
                'is_primary' => true,
                'verification_token' => \App\Models\SiteDomain::newToken(),
                'verified_at' => now(),
            ]);

            $site->update(['status' => 'live']);

            return $site->fresh();
        });
    }
}
