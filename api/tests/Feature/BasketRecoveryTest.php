<?php

namespace Tests\Feature;

use App\Domain\Baskets\Baskets;
use App\Domain\Sites\SiteProvisioner;
use App\Models\BasketRecovery;
use App\Models\Event;
use App\Models\ExternalOrder;
use App\Models\Hold;
use App\Models\MessageDelivery;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * Buyers who got as far as their own name, and what may honestly be done about it.
 *
 * The line this feature refuses to cross is the first thing tested here: a recovery exists only
 * for somebody who **submitted the checkout**. Nothing is ever written for an address that was
 * merely typed into a box, and nothing is written for a shop that owns its own basket.
 *
 * The second is the seats. They are gone by the time anybody reads the message, so following the
 * link tries to take the same ones again and refuses plainly when it cannot. A link that quietly
 * seated somebody somewhere else would be worse than a link that fails.
 */
class BasketRecoveryTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function only_a_submitted_checkout_becomes_a_recovery(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);

        // A basket nobody submitted: a hold, and no order. There is no address, and there is no
        // column here that could hold one.
        $this->hold($fixture, [0])->assertCreated();

        $this->assertSame(0, $this->abandoned($fixture)->count());

        // And one that was submitted, whose payment never came back.
        $this->newBrowser();
        $this->unfinished($fixture, [1]);

        $this->assertSame(1, $this->abandoned($fixture)->count());
    }

    #[Test]
    public function a_purchase_that_is_still_warm_is_left_alone(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->unfinished($fixture, [0]);

        // Written a minute ago. The gateway may still be about to answer, and telling somebody
        // their booking is unfinished when their card has been charged is the worst message here.
        $this->assertSame(0, $this->abandoned($fixture, 60)->count());
        $this->assertSame(1, $this->abandoned($fixture, 0)->count());
    }

    #[Test]
    public function a_shop_that_owns_its_own_basket_is_left_alone(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->unfinished($fixture, [0]);

        // The same order, but registered by a WooCommerce shop rather than by a hosted site.
        $this->inTenant($fixture, fn () => ExternalOrder::query()->update([
            'metadata' => ['source' => 'woocommerce'],
        ]));

        $this->assertSame(0, $this->abandoned($fixture, 0)->count());
    }

    #[Test]
    public function a_buyer_is_written_to_once_however_often_the_sweeper_runs(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->switchOn($fixture);
        $this->unfinished($fixture, [0]);

        $this->artisan('baskets:recover', ['--after' => 0])->assertSuccessful();
        $this->artisan('baskets:recover', ['--after' => 0])->assertSuccessful();
        $this->artisan('baskets:recover', ['--after' => 0])->assertSuccessful();

        $this->inTenant($fixture, function () {
            $this->assertSame(1, BasketRecovery::count());
            $this->assertSame('sent', BasketRecovery::first()->status);
            $this->assertSame(1, MessageDelivery::where('kind', 'order.unfinished')->count());
        });
    }

    #[Test]
    public function the_message_carries_a_way_back_and_a_way_out(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->switchOn($fixture);
        $this->unfinished($fixture, [0]);

        $this->artisan('baskets:recover', ['--after' => 0])->assertSuccessful();

        $recovery = $this->recovery($fixture);
        // Something really was sent about this basket.
        $this->inTenant($fixture, fn () => $this->assertSame(
            1,
            MessageDelivery::where('kind', 'order.unfinished')->count(),
        ));

        /*
         * And what it said. The delivery keeps only a 200-character preview — enough for the
         * organiser's log and not enough to reach the links, which sit at the bottom — so the
         * wording is rendered here with the same variables the send used.
         */
        $order = $recovery->order;
        $site = $this->inTenant($fixture, fn () => Site::firstOrFail());
        $body = $this->inTenant($fixture, fn () => \App\Domain\Messaging\MessageRenderer::render(
            (string) __('messaging.defaults.order_unfinished.body'),
            app(\App\Domain\Messaging\OrderMessages::class)->variables($order->loadMissing(
                ['event.venue', 'allocations', 'apiClient']
            ), 'en') + [
                'link' => $site->url('/basket/'.$recovery->token),
                'decline' => $site->url('/basket/'.$recovery->token.'/no-thanks'),
            ],
        ));

        $this->assertStringContainsString('/basket/'.$recovery->token, $body);
        $this->assertStringContainsString('/basket/'.$recovery->token.'/no-thanks', $body);
    }

    #[Test]
    public function nothing_is_sent_until_an_organiser_turns_it_on(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->unfinished($fixture, [0]);

        // The kind is optional, and an optional kind is off until somebody says otherwise. A
        // platform that wrote to an organiser's buyers on their behalf without being asked would
        // be spending their reputation, not its own.
        $this->artisan('baskets:recover', ['--after' => 0])->assertSuccessful();

        $this->inTenant($fixture, function () {
            // The basket is still noticed — the organiser can see it on the screen and write by
            // hand — but nothing went to the buyer.
            $this->assertSame(1, BasketRecovery::count());
            $this->assertSame(0, MessageDelivery::where('kind', 'order.unfinished')->count());
        });
    }

    /* ------------------------------------------------------------------ following the link */

    #[Test]
    public function the_link_puts_the_same_seats_back_in_the_basket(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->switchOn($fixture);
        $this->unfinished($fixture, [0, 1]);
        $this->artisan('baskets:recover', ['--after' => 0])->assertSuccessful();

        $recovery = $this->recovery($fixture);

        // A different browser entirely: they are following a link out of an email.
        $this->newBrowser();
        $this->get('http://northgate.test/basket/'.$recovery->token)
            ->assertRedirect('/checkout');

        $hold = $this->inTenant($fixture, fn () => Hold::where('status', 'active')
            ->with('items')->orderByDesc('created_at')->firstOrFail());

        $this->assertCount(2, $hold->items);
        $this->assertEqualsCanonicalizing(
            [$fixture['seats'][0]->id, $fixture['seats'][1]->id],
            $hold->items->pluck('seat_id')->all(),
        );
    }

    #[Test]
    public function a_seat_somebody_else_has_taken_is_said_plainly(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->switchOn($fixture);
        $this->unfinished($fixture, [0]);
        $this->artisan('baskets:recover', ['--after' => 0])->assertSuccessful();

        $recovery = $this->recovery($fixture);

        // Somebody else takes the seat in the meantime, which after an hour is the ordinary case.
        $this->newBrowser();
        $this->hold($fixture, [0])->assertCreated();

        $this->newBrowser();
        $this->get('http://northgate.test/basket/'.$recovery->token)
            // Sent to the picker rather than seated somewhere else without being told.
            ->assertRedirect('/events/'.$fixture['event']->public_id)
            ->assertSessionHas('seatmap_message');

        $this->assertNull(session('seatmap_hold'));
    }

    #[Test]
    public function coming_back_and_buying_is_recorded_as_having_worked(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->switchOn($fixture);
        $this->unfinished($fixture, [0]);
        $this->artisan('baskets:recover', ['--after' => 0])->assertSuccessful();

        $recovery = $this->recovery($fixture);

        $this->newBrowser();
        $this->get('http://northgate.test/basket/'.$recovery->token)->assertRedirect('/checkout');
        $this->post('http://northgate.test/checkout', [
            'name' => 'Amina Farsi', 'email' => 'amina@example.test', 'gateway' => 'offline',
        ])->assertRedirect();

        $fresh = $recovery->fresh();

        $this->assertSame('recovered', $fresh->status);
        $this->assertNotNull($fresh->recovered_order_id);
        $this->assertNotSame($fresh->external_order_row_id, $fresh->recovered_order_id);
    }

    #[Test]
    public function no_thanks_means_never_again(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->switchOn($fixture);
        $this->unfinished($fixture, [0]);
        $this->artisan('baskets:recover', ['--after' => 0])->assertSuccessful();

        $recovery = $this->recovery($fixture);

        $this->newBrowser();
        $this->get('http://northgate.test/basket/'.$recovery->token.'/no-thanks')
            ->assertRedirect('/');

        $this->assertSame('declined', $recovery->fresh()->status);

        // And the link is dead afterwards, rather than quietly working again.
        $this->get('http://northgate.test/basket/'.$recovery->token)
            ->assertRedirect('/')
            ->assertSessionHas('seatmap_message');
    }

    #[Test]
    public function a_basket_belongs_to_the_site_that_sent_it(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->switchOn($fixture);
        $this->unfinished($fixture, [0]);
        $this->artisan('baskets:recover', ['--after' => 0])->assertSuccessful();

        $token = $this->recovery($fixture)->token;

        // Another organiser's site, on its own hostname. A token that resolved here would be a
        // basket crossing a border nothing else in this application lets anything cross.
        $other = $this->makeSellableEvent();
        $this->makeSite($other['tenant'], 'westgate.test');

        $this->newBrowser();
        $this->get('http://westgate.test/basket/'.$token)->assertNotFound();
    }

    /* ------------------------------------------------------------------ the screen */

    #[Test]
    public function the_screen_counts_what_came_of_writing_to_people(): void
    {
        $fixture = $this->makeSellableEvent(rows: 4, perRow: 6);
        $this->makeSite($fixture['tenant']);
        $this->switchOn($fixture);

        // Three unfinished purchases; one of them comes back and buys.
        foreach ([[0], [1], [2]] as $seats) {
            $this->newBrowser();
            $this->unfinished($fixture, $seats);
        }

        $this->artisan('baskets:recover', ['--after' => 0])->assertSuccessful();

        $recovery = $this->inTenant($fixture, fn () => BasketRecovery::orderBy('created_at')->firstOrFail());

        $this->newBrowser();
        $this->get('http://northgate.test/basket/'.$recovery->token)->assertRedirect('/checkout');
        $this->post('http://northgate.test/checkout', [
            'name' => 'Amina Farsi', 'email' => 'amina@example.test', 'gateway' => 'offline',
        ])->assertRedirect();

        $user = $this->makeUser($fixture['tenant']);

        $this->actingAs($user)->getJson('/v1/baskets')
            ->assertOk()
            ->assertJsonPath('summary.baskets', 3)
            ->assertJsonPath('summary.written', 3)
            ->assertJsonPath('summary.recovered', 1)
            // One in three, of the people actually written to.
            ->assertJsonPath('summary.rate', 33);
    }

    #[Test]
    public function writing_by_hand_refuses_rather_than_sending_nothing(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        // The kind is off, which is where every account starts.
        $this->unfinished($fixture, [0]);
        $this->artisan('baskets:recover', ['--after' => 0])->assertSuccessful();

        $recovery = $this->recovery($fixture);
        $user = $this->makeUser($fixture['tenant']);

        // A button that reported success and delivered nothing would be found out weeks later,
        // from a recovery rate of zero.
        $this->actingAs($user)->postJson('/v1/baskets/'.$recovery->id.'/send', [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'basket_message_off');

        $this->assertSame('waiting', $recovery->fresh()->status);

        // Switched on, the same press works.
        $this->switchOn($fixture);

        $this->actingAs($user)->postJson('/v1/baskets/'.$recovery->id.'/send', [])
            ->assertOk()
            ->assertJsonPath('status', 'sent');

        $this->inTenant($fixture, fn () => $this->assertSame(
            1,
            MessageDelivery::where('kind', 'order.unfinished')->count(),
        ));
    }

    #[Test]
    public function writing_by_hand_is_not_a_way_to_write_twice(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->switchOn($fixture);
        $this->unfinished($fixture, [0]);
        $this->artisan('baskets:recover', ['--after' => 0])->assertSuccessful();

        $recovery = $this->recovery($fixture);
        $user = $this->makeUser($fixture['tenant']);

        $this->actingAs($user)->postJson('/v1/baskets/'.$recovery->id.'/send', [])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'basket_already_written');
    }

    #[Test]
    public function a_night_that_has_been_stops_being_a_sale_in_play(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->switchOn($fixture);
        $this->unfinished($fixture, [0]);
        $this->artisan('baskets:recover', ['--after' => 0])->assertSuccessful();

        $this->inTenant($fixture, fn () => Event::whereKey($fixture['event']->id)
            ->update(['starts_at' => now()->subDay(), 'ends_at' => now()->subDay()]));

        $this->artisan('baskets:recover', ['--after' => 0])->assertSuccessful();

        $this->assertSame('expired', $this->recovery($fixture)->status);
    }

    #[Test]
    public function a_payment_that_settled_after_all_counts_as_a_return_and_not_a_loss(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->switchOn($fixture);
        // The hold is left alive here, because a gateway that answers after the seats have gone
        // back on sale cannot confirm anything at all — the order is dead either way, and this
        // test is about the one that is not.
        $this->unfinished($fixture, [0], expireHold: false);
        $this->artisan('baskets:recover', ['--after' => 0])->assertSuccessful();

        // The gateway answered late and the order confirmed. The buyer came back — through the
        // payment rather than through the link — and the count has to say so.
        $order = $this->inTenant($fixture, fn () => ExternalOrder::where('status', 'pending')->firstOrFail());
        $this->inTenant($fixture, fn () => app(\App\Domain\Orders\OrderService::class)
            ->confirm($order, $order->buyer ?? [], now()));

        $this->artisan('baskets:recover', ['--after' => 0])->assertSuccessful();

        $this->assertSame('recovered', $this->recovery($fixture)->status);
    }

    #[Test]
    public function a_payment_that_failed_is_a_loss_and_says_so(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->switchOn($fixture);
        $this->unfinished($fixture, [0]);
        $this->artisan('baskets:recover', ['--after' => 0])->assertSuccessful();

        // The gateway came back and said no. There is nothing left to recover, and a row left
        // open would sit in the organiser's list looking like a sale still in play.
        $order = $this->inTenant($fixture, fn () => ExternalOrder::where('status', 'pending')->firstOrFail());
        $this->inTenant($fixture, fn () => app(\App\Domain\Orders\OrderService::class)
            ->cancel($order, 'payment_failed'));

        $this->artisan('baskets:recover', ['--after' => 0])->assertSuccessful();

        $this->assertSame('expired', $this->recovery($fixture)->status);
    }

    /* ------------------------------------------------------------------------------ helpers */

    /**
     * Switch the message on, the way an organiser does on the messaging screen.
     *
     * An optional kind sends nothing until somebody says otherwise, so every test below that
     * expects an email has to say so first — which is the point of the setting.
     */
    private function switchOn(array $fixture): void
    {
        $this->inTenant($fixture, fn () => \App\Models\MessageChannelSetting::updateOrCreate(
            ['tenant_id' => $fixture['tenant']->id, 'kind' => 'order.unfinished', 'channel' => 'email'],
            ['enabled' => true],
        ));
    }

    private function abandoned(array $fixture, int $after = 0)
    {
        return $this->inTenant($fixture, fn () => app(Baskets::class)->abandoned($after));
    }

    private function recovery(array $fixture): BasketRecovery
    {
        return $this->inTenant(
            $fixture,
            fn () => BasketRecovery::orderByDesc('created_at')->firstOrFail()
        );
    }

    /**
     * A checkout that was submitted and whose payment never came back.
     *
     * Exactly what a redirect gateway leaves behind when the buyer closes the tab: an order
     * registered against a hold, waiting to be paid for, that nothing ever settled. The hold is
     * then aged out the way the sweeper ages one out, because by the time anybody reads a recovery
     * message an hour later the seats have long since gone back on sale — and a test where they
     * had not would be testing a situation that never happens.
     *
     * @param  list<int>  $seats
     */
    private function unfinished(array $fixture, array $seats, bool $expireHold = true): void
    {
        $this->hold($fixture, $seats)->assertCreated();
        $token = (string) session('seatmap_hold');

        $this->inTenant($fixture, function () use ($token, $expireHold) {
            $site = Site::firstOrFail();
            $hold = Hold::where('token', $token)->firstOrFail();

            [$order] = app(\App\Domain\Orders\OrderService::class)->register(
                app(\App\Domain\Sites\StorefrontCheckout::class)->clientFor($site),
                'site-'.substr(hash('sha256', $token), 0, 24),
                $token,
                ['name' => 'Amina Farsi', 'email' => 'amina@example.test'],
                [
                    'source' => 'hosted_site',
                    'site_id' => $site->id,
                    'gateway' => 'offline',
                    'payment_reference' => 'abandoned',
                ],
            );

            $order->forceFill(['total_amount' => (int) $hold->total_amount])->save();

            if (! $expireHold) {
                return;
            }

            // An hour later. The seats are back on sale and only the record of what was chosen
            // survives, which is the whole situation a recovery link has to cope with.
            \App\Models\HoldItem::where('hold_id', $hold->id)
                ->update(['released_at' => now(), 'updated_at' => now()]);
            $hold->forceFill([
                'status' => 'expired',
                'expires_at' => now()->subHour(),
                'released_at' => now(),
            ])->save();
        });

        $this->newBrowser();
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

    private function makeSite($tenant, string $hostname = 'northgate.test'): Site
    {
        return app(TenantContext::class)->runAs($tenant, function () use ($tenant, $hostname) {
            $site = app(SiteProvisioner::class)->create($tenant->name);

            SiteDomain::create([
                'site_id' => $site->id,
                'hostname' => $hostname,
                'is_primary' => true,
                'verification_token' => SiteDomain::newToken(),
                'verified_at' => now(),
            ]);

            $site->update(['status' => 'live']);

            return $site->fresh();
        });
    }
}
