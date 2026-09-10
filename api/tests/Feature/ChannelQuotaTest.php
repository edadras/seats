<?php

namespace Tests\Feature;

use App\Domain\Channels\ChannelQuotas;
use App\Domain\Inventory\HoldService;
use App\Domain\Sites\SiteProvisioner;
use App\Models\ApiClient;
use App\Models\ChannelQuota;
use App\Models\Event;
use App\Models\EventSeatOverride;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * Two ways of keeping part of a house back, and neither of them is "blocked".
 *
 * **A house seat** is a blocked seat with a label saying who it is being kept for. That is not a
 * trick — it is what makes the change safe. Every public path already excludes blocked seats, so
 * the first test here is that nothing public started offering them; the second is that the one
 * caller who is supposed to sell them can.
 *
 * **A quota** is the other shape of the same wish: not "these seats" but "this many". What a
 * channel has taken is counted rather than stored, under the same advisory lock the seats are sold
 * under, and a cancelled booking gives its places back.
 */
class ChannelQuotaTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    /* ------------------------------------------------------------------ house seats */

    #[Test]
    public function a_house_seat_is_off_public_sale(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->house($fixture, 0, 'Production');

        // The site is a channel like any other, and the public may not have it.
        $this->hold($fixture, [0])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'seat_unavailable');

        // Nor the embed API, which is the same seats through a different door.
        $availability = $this->getJson('/v1/embed/events/'.$fixture['event']->public_id.'/availability')
            ->assertOk()
            ->json('seats');

        $held = collect($availability)->firstWhere('seat_id', $fixture['seats'][0]->id);

        $this->assertSame('blocked', $held['state'] ?? null);
    }

    #[Test]
    public function the_counter_may_sell_a_house_seat(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->house($fixture, 0, 'Production');

        $hold = $this->inTenant($fixture, fn () => app(HoldService::class)->create(
            Event::findOrFail($fixture['event']->id),
            [$fixture['seats'][0]->id],
            'counter-session',
            $this->counterClient($fixture)->id,
        ));

        $this->assertCount(1, $hold->items);
    }

    #[Test]
    public function the_counter_screen_offers_a_house_seat_and_says_who_it_is_for(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->house($fixture, 0, 'Production');
        // Blocked with no label, in the same hall: the screen must tell the two apart.
        $this->inTenant($fixture, fn () => EventSeatOverride::create([
            'tenant_id' => $fixture['tenant']->id,
            'event_id' => $fixture['event']->id,
            'seat_id' => $fixture['seats'][1]->id,
            'blocked' => true,
        ]));

        $seats = collect($this->actingAs($this->makeUser($fixture['tenant']))
            ->getJson('/v1/events/'.$fixture['event']->id.'/counter')
            ->assertOk()
            ->json('sections'))
            ->flatMap(fn (array $section) => $section['rows'])
            ->flatMap(fn (array $row) => $row['seats'])
            ->keyBy('id');

        $this->assertSame('available', $seats[$fixture['seats'][0]->id]['state']);
        $this->assertSame('Production', $seats[$fixture['seats'][0]->id]['held_for']);

        // The camera position is nobody's to sell, at this window or any other.
        $this->assertSame('blocked', $seats[$fixture['seats'][1]->id]['state']);
        $this->assertNull($seats[$fixture['seats'][1]->id]['held_for']);
    }

    #[Test]
    public function the_window_sells_a_house_seat_and_the_sale_belongs_to_the_counter(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->house($fixture, 0, 'Production');

        $this->actingAs($this->makeUser($fixture['tenant']))
            ->postJson('/v1/events/'.$fixture['event']->id.'/sell', [
                'seat_ids' => [$fixture['seats'][0]->id],
                'buyer' => ['name' => 'The director’s mother'],
                'payment' => 'comp',
            ])->assertCreated();

        $this->inTenant($fixture, function () use ($fixture) {
            $counter = ApiClient::where('kind', 'box_office')->firstOrFail();

            // Attributed to the channel that sold it, which is what makes a quota on the counter
            // mean anything and what puts the sale on the right line of a settlement report.
            $this->assertSame(1, \App\Models\Allocation::where('event_id', $fixture['event']->id)
                ->where('api_client_id', $counter->id)
                ->where('status', 'active')
                ->count());
        });
    }

    #[Test]
    public function the_counter_is_a_channel_like_any_other_and_can_be_capped(): void
    {
        $fixture = $this->makeSellableEvent(rows: 4, perRow: 6);
        $this->quota($fixture, $this->counterClient($fixture)->id, 1);
        $user = $this->makeUser($fixture['tenant']);

        $sell = fn (int $seat) => $this->actingAs($user)
            ->postJson('/v1/events/'.$fixture['event']->id.'/sell', [
                'seat_ids' => [$fixture['seats'][$seat]->id],
                'buyer' => ['name' => 'A school'],
                'payment' => 'owed',
            ]);

        $sell(0)->assertCreated();

        // One was the whole allowance. The refusal names what is left rather than merely saying no.
        $sell(1)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'channel_quota_reached')
            ->assertJsonPath('error.details.left', 0);
    }

    #[Test]
    public function a_seat_blocked_outright_is_sellable_by_nobody(): void
    {
        $fixture = $this->makeSellableEvent();
        // Blocked with no label: a sightline, a camera position. Not a house seat.
        $this->inTenant($fixture, fn () => EventSeatOverride::create([
            'tenant_id' => $fixture['tenant']->id,
            'event_id' => $fixture['event']->id,
            'seat_id' => $fixture['seats'][0]->id,
            'blocked' => true,
        ]));

        $this->expectExceptionMessageMatches('/no longer available/');

        $this->inTenant($fixture, fn () => app(HoldService::class)->create(
            Event::findOrFail($fixture['event']->id),
            [$fixture['seats'][0]->id],
            'counter-session',
            $this->counterClient($fixture)->id,
        ));
    }

    #[Test]
    public function releasing_a_house_seat_puts_it_back_on_sale(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->house($fixture, 0, 'Press');
        $user = $this->makeUser($fixture['tenant']);

        $this->hold($fixture, [0])->assertStatus(409);

        // The press did not come. The seats go back on sale for everybody, which is one press of
        // "unblock" and clears the label with it.
        $this->actingAs($user)->putJson('/v1/events/'.$fixture['event']->id.'/seat-prices', [
            'seats' => [[
                'seat_id' => $fixture['seats'][0]->id,
                'amount' => null,
                'zone_key' => null,
                'blocked' => false,
                'held_for' => null,
            ]],
        ])->assertOk();

        $this->newBrowser();
        $this->hold($fixture, [0])->assertCreated();
    }

    #[Test]
    public function a_label_without_a_block_is_not_stored(): void
    {
        $fixture = $this->makeSellableEvent();
        $user = $this->makeUser($fixture['tenant']);

        // A label on a seat that is on public sale would be a note nobody reads — and the database
        // refuses it outright, so the API must not send one.
        $this->actingAs($user)->putJson('/v1/events/'.$fixture['event']->id.'/seat-prices', [
            'seats' => [[
                'seat_id' => $fixture['seats'][0]->id,
                'amount' => null,
                'zone_key' => null,
                'blocked' => false,
                'held_for' => 'Production',
            ]],
        ])->assertOk();

        $this->inTenant($fixture, fn () => $this->assertSame(
            0,
            EventSeatOverride::whereNotNull('held_for')->count(),
        ));
    }

    /* ------------------------------------------------------------------ quotas */

    #[Test]
    public function a_channel_cannot_sell_past_its_allocation(): void
    {
        $fixture = $this->makeSellableEvent(rows: 4, perRow: 6);
        $this->makeSite($fixture['tenant']);

        // The site takes two, and is promised three.
        $this->hold($fixture, [0, 1])->assertCreated();
        $this->quota($fixture, $this->siteClient($fixture), 3);

        $this->newBrowser();
        $this->hold($fixture, [2, 3])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'channel_quota_reached')
            ->assertJsonPath('error.details.left', 1);

        // One is still within it.
        $this->newBrowser();
        $this->hold($fixture, [2])->assertCreated();

        // And now there is nothing left at all.
        $this->newBrowser();
        $this->hold($fixture, [3])
            ->assertStatus(409)
            ->assertJsonPath('error.details.left', 0);
    }

    #[Test]
    public function a_channel_with_no_quota_has_no_limit(): void
    {
        $fixture = $this->makeSellableEvent(rows: 4, perRow: 6);
        $this->makeSite($fixture['tenant']);

        // The ordinary case, and the one almost every account is in.
        foreach ([[0], [1], [2], [3], [4]] as $seats) {
            $this->newBrowser();
            $this->hold($fixture, $seats)->assertCreated();
        }

        $this->assertSame(5, $this->taken($fixture));
    }

    #[Test]
    public function an_allocation_counts_places_and_not_rows(): void
    {
        $fixture = $this->makeSellableEvent(rows: 4, perRow: 6);
        $this->makeSite($fixture['tenant']);
        $this->quota($fixture, $this->siteClient($fixture), 3);

        // Three seats in one basket is three places, not one — a quota that counted rows would
        // let an agent sell a stadium in a single hold.
        $this->hold($fixture, [0, 1, 2])->assertCreated();

        $this->assertSame(3, $this->taken($fixture));

        $this->newBrowser();
        $this->hold($fixture, [3])->assertStatus(409);
    }

    #[Test]
    public function a_basket_that_goes_away_gives_its_allowance_back(): void
    {
        $fixture = $this->makeSellableEvent(rows: 4, perRow: 6);
        $this->makeSite($fixture['tenant']);
        $this->quota($fixture, $this->siteClient($fixture), 2);

        $this->hold($fixture, [0, 1])->assertCreated();
        $this->assertSame(2, $this->taken($fixture));

        // The basket expires. The agent who returns unsold seats gets their allowance back.
        $this->inTenant($fixture, fn () => \App\Models\Hold::query()
            ->update(['status' => 'expired', 'expires_at' => now()->subMinute()]));

        $this->assertSame(0, $this->taken($fixture));

        $this->newBrowser();
        $this->hold($fixture, [2, 3])->assertCreated();
    }

    #[Test]
    public function one_channel_cannot_eat_another_channel_s_allocation(): void
    {
        $fixture = $this->makeSellableEvent(rows: 4, perRow: 6);
        $this->makeSite($fixture['tenant']);
        $this->quota($fixture, $this->siteClient($fixture), 1);

        $this->hold($fixture, [0])->assertCreated();

        // The site is finished, and the counter — which has no quota — is not.
        $this->newBrowser();
        $this->hold($fixture, [1])->assertStatus(409);

        $hold = $this->inTenant($fixture, fn () => app(HoldService::class)->create(
            Event::findOrFail($fixture['event']->id),
            [$fixture['seats'][1]->id],
            'counter-session',
            $this->counterClient($fixture)->id,
        ));

        $this->assertCount(1, $hold->items);
    }

    #[Test]
    public function the_screen_lists_every_channel_and_what_it_has_done(): void
    {
        $fixture = $this->makeSellableEvent(rows: 4, perRow: 6);
        $this->makeSite($fixture['tenant']);
        $this->hold($fixture, [0, 1])->assertCreated();

        $user = $this->makeUser($fixture['tenant']);

        $rows = $this->actingAs($user)
            ->getJson('/v1/events/'.$fixture['event']->id.'/quotas')
            ->assertOk()
            ->json('data');

        $site = collect($rows)->firstWhere('api_client_id', $this->siteClient($fixture));

        // Listed with no limit — an organiser deciding what to promise an agent needs to see what
        // the website is already doing.
        $this->assertNull($site['places']);
        $this->assertSame(2, $site['taken']);
        $this->assertNull($site['left']);
    }

    #[Test]
    public function the_website_is_listed_before_it_has_sold_anything(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);

        // A hosted site's channel identity is made on first sale. Asking who the channels are is
        // reason enough for it to exist: a cap set after the first ticket is a cap set too late.
        $rows = $this->actingAs($this->makeUser($fixture['tenant']))
            ->getJson('/v1/events/'.$fixture['event']->id.'/quotas')
            ->assertOk()
            ->json('data');

        $site = collect($rows)->firstWhere('kind', 'storefront');

        $this->assertNotNull($site);
        $this->assertSame(0, $site['taken']);
    }

    #[Test]
    public function a_promise_can_be_made_and_finished_with(): void
    {
        $fixture = $this->makeSellableEvent(rows: 4, perRow: 6);
        $this->makeSite($fixture['tenant']);
        $client = $this->siteClient($fixture);
        $user = $this->makeUser($fixture['tenant']);

        $this->actingAs($user)->putJson('/v1/events/'.$fixture['event']->id.'/quotas', [
            'quotas' => [['api_client_id' => $client, 'places' => 4, 'note' => 'The website']],
        ])->assertOk();

        $this->inTenant($fixture, fn () => $this->assertSame(4, (int) ChannelQuota::firstOrFail()->places));

        // A PUT of the whole set: a channel left out has no limit any more, which is the only way
        // "the agent's allocation is finished with" can be said.
        $this->actingAs($user)->putJson('/v1/events/'.$fixture['event']->id.'/quotas', [
            'quotas' => [],
        ])->assertOk();

        $this->inTenant($fixture, fn () => $this->assertSame(0, ChannelQuota::count()));
    }

    /* ------------------------------------------------------------------------------ helpers */

    private function house(array $fixture, int $seat, string $label): void
    {
        $this->inTenant($fixture, fn () => EventSeatOverride::create([
            'tenant_id' => $fixture['tenant']->id,
            'event_id' => $fixture['event']->id,
            'seat_id' => $fixture['seats'][$seat]->id,
            'blocked' => true,
            'held_for' => $label,
        ]));
    }

    private function quota(array $fixture, string $clientId, int $places): void
    {
        $this->inTenant($fixture, fn () => ChannelQuota::updateOrCreate(
            ['event_id' => $fixture['event']->id, 'api_client_id' => $clientId],
            ['tenant_id' => $fixture['tenant']->id, 'places' => $places],
        ));
    }

    private function taken(array $fixture): int
    {
        return $this->inTenant($fixture, fn () => app(ChannelQuotas::class)->taken(
            Event::findOrFail($fixture['event']->id),
            $this->siteClient($fixture),
        ));
    }

    /**
     * The hosted site's own channel — the client its holds and orders are attributed to.
     *
     * Made on first use rather than at provisioning, so this asks for it the same way the site
     * does: a test that read the table directly would fail on the account that has not sold
     * anything yet, which is exactly the account an organiser sets a quota on.
     */
    private function siteClient(array $fixture): string
    {
        return $this->inTenant($fixture, fn () => app(\App\Domain\Sites\StorefrontCheckout::class)
            ->clientFor(Site::firstOrFail())->id);
    }

    private function counterClient(array $fixture): ApiClient
    {
        return $this->inTenant($fixture, fn () => ApiClient::firstOrCreate(
            ['tenant_id' => $fixture['tenant']->id, 'kind' => 'box_office'],
            ['name' => 'Box office', 'status' => 'active'],
        ));
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
