<?php

namespace Tests\Feature;

use App\Domain\Access\AccessCodes;
use App\Domain\Access\SaleWindow;
use App\Domain\Sites\SiteProvisioner;
use App\Models\AccessCode;
use App\Models\Event;
use App\Models\Hold;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * Who may buy, and when.
 *
 * The thing being defended is the presale's whole purpose: a sale that is open to a mailing list
 * and to nobody else. It is defended at hold time rather than at the till, because a presale
 * checked at the till is a race anybody may join and only lose after choosing their seats — and
 * these tests are mostly about that choice and what follows from it.
 */
class AccessCodeTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function an_event_with_no_presale_behaves_exactly_as_it_always_did(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);

        // Both instants null is what every event that has never heard of a presale carries.
        $this->assertSame(SaleWindow::OPEN, app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => SaleWindow::state(Event::findOrFail($fixture['event']->id))
        ));

        $this->hold($fixture, [0])->assertCreated();
    }

    #[Test]
    public function a_presale_turns_away_somebody_with_no_code(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->window($fixture, presale: now()->subHour(), onSale: now()->addWeek());

        $this->hold($fixture, [0])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'access_code_required');

        // And nothing was taken out of inventory on the way to being refused.
        $this->assertSame(0, app(TenantContext::class)->runAs($fixture['tenant'], fn () => Hold::count()));
    }

    #[Test]
    public function a_presale_lets_somebody_with_a_code_through(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->window($fixture, presale: now()->subHour(), onSale: now()->addWeek());
        $code = $this->code($fixture, ['code' => 'MEMBERS']);

        $this->hold($fixture, [0], 'members')->assertCreated();

        // Spelling is normalised: "members" and "MEMBERS" are one code, not two near-misses.
        app(TenantContext::class)->runAs($fixture['tenant'], function () use ($code) {
            $this->assertSame(1, app(AccessCodes::class)->used($code->fresh()));
            $this->assertSame($code->id, Hold::firstOrFail()->access_code_id);
        });
    }

    #[Test]
    public function a_wrong_code_is_told_which_kind_of_wrong_it_is(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->window($fixture, presale: now()->subHour(), onSale: now()->addWeek());
        $this->code($fixture, ['code' => 'MEMBERS']);

        // "That code is not right" and "that presale opens on Friday" send a person to two
        // different next steps, and only one of them is the telephone.
        $this->hold($fixture, [0], 'NOPE')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'unknown_code');

        $this->code($fixture, ['code' => 'LATER', 'starts_at' => now()->addDay()]);

        $this->hold($fixture, [0], 'LATER')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'code_not_live');
    }

    #[Test]
    public function before_the_presale_opens_only_an_always_code_gets_in(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->window($fixture, presale: now()->addDay(), onSale: now()->addWeek());
        $this->code($fixture, ['code' => 'MEMBERS', 'opens' => 'presale']);
        $this->code($fixture, ['code' => 'STAFF', 'opens' => 'always']);

        // A presale code before the presale is a code for the right sale on the wrong night.
        $this->hold($fixture, [0], 'MEMBERS')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'presale_not_open');

        $this->hold($fixture, [1], 'STAFF')->assertCreated();
    }

    #[Test]
    public function once_general_sale_opens_nobody_needs_a_code(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->window($fixture, presale: now()->subWeek(), onSale: now()->subHour());

        $this->hold($fixture, [0])->assertCreated();
    }

    #[Test]
    public function a_wrong_code_does_not_stop_a_sale_that_is_open_to_everybody(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);

        // Somebody who typed a code into a shop that no longer needs one must not be refused a
        // sale everybody else can make.
        $this->hold($fixture, [0], 'RUBBISH')->assertCreated();
    }

    #[Test]
    public function a_cap_counts_live_uses_and_not_presses(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->window($fixture, presale: now()->subHour(), onSale: now()->addWeek());
        $code = $this->code($fixture, ['code' => 'ONLYONE', 'max_uses' => 1]);

        $this->hold($fixture, [0], 'ONLYONE')->assertCreated();

        // Letting go gives the use straight back, in the same breath as the seats.
        $this->post('http://northgate.test/_store/release')->assertOk();

        $this->assertSame(0, $this->used($fixture, $code), 'a released basket keeps nobody out');

        $this->newBrowser();
        $this->hold($fixture, [0], 'ONLYONE')->assertCreated();

        // A second browser, with the same code, on a code that allows one booking.
        $this->newBrowser();
        $this->hold($fixture, [1], 'ONLYONE')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'code_used_up');

        /*
         * And the first buyer simply wanders off, without pressing anything.
         *
         * Nothing sweeps expired holds — a hold is dead when its instant passes, and every query
         * that counts one says so. If the cap were a stored counter this is where a mailing list
         * of a hundred would become a mailing list of one.
         */
        app(TenantContext::class)->runAs($fixture['tenant'], fn () => Hold::query()
            ->where('status', 'active')
            ->update(['expires_at' => now()->subMinute()]));

        $this->assertSame(0, $this->used($fixture, $code));

        $this->newBrowser();
        $this->hold($fixture, [2], 'ONLYONE')->assertCreated();

        $this->assertSame(1, $this->used($fixture, $code));
    }

    #[Test]
    public function a_code_can_be_capped_at_a_number_of_seats(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->window($fixture, presale: now()->subHour(), onSale: now()->addWeek());
        $this->code($fixture, ['code' => 'PAIR', 'max_seats' => 2]);

        // A code emailed to a mailing list that let one person take the front row is a code that
        // did the opposite of what it was for.
        $this->hold($fixture, [0, 1, 2], 'PAIR')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'access_code_seat_limit')
            ->assertJsonPath('error.details.max_seats', 2);

        $this->hold($fixture, [0, 1], 'PAIR')->assertCreated();
    }

    #[Test]
    public function a_booking_keeps_the_use_the_hold_spent(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->window($fixture, presale: now()->subHour(), onSale: now()->addWeek());
        $code = $this->code($fixture, ['code' => 'MEMBERS', 'max_uses' => 1]);

        $this->hold($fixture, [0], 'MEMBERS')->assertCreated();
        $this->post('http://northgate.test/checkout', [
            'name' => 'Amina Farsi',
            'email' => 'amina@example.test',
            'gateway' => 'offline',
        ])->assertRedirect();

        // The hold is finished with; the use is not. Releasing it here would leak the cap one
        // seat at a time.
        app(TenantContext::class)->runAs($fixture['tenant'], function () use ($code) {
            $this->assertSame(1, app(AccessCodes::class)->used($code->fresh()));
            $this->assertNotNull(
                \App\Models\AccessCodeUse::firstOrFail()->external_order_row_id,
                'the use should now point at the booking'
            );
        });
    }

    #[Test]
    public function the_page_offers_a_box_instead_of_the_seats(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->window($fixture, presale: now()->subHour(), onSale: now()->addWeek());
        $this->code($fixture, ['code' => 'MEMBERS']);

        $url = 'http://northgate.test/events/'.$fixture['event']->public_id;

        $this->get($url)
            ->assertOk()
            ->assertSee(__('site.access.haveACode'), false)
            ->assertSee(__('site.access.presaleOnly'), false)
            // The picker is not drawn at all: whether somebody may buy is the server's answer.
            ->assertDontSee('seatmap-widget__stage', false);

        $this->postJson('http://northgate.test/_store/unlock', [
            'event_public_id' => $fixture['event']->public_id,
            'code' => 'members',
        ])->assertOk()->assertJsonPath('ok', true);

        $this->get($url)
            ->assertOk()
            ->assertSee('seatmap-widget', false)
            ->assertDontSee(__('site.access.haveACode'), false);
    }

    #[Test]
    public function a_refused_code_says_why_and_unlocks_nothing(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->window($fixture, presale: now()->subHour(), onSale: now()->addWeek());

        $this->postJson('http://northgate.test/_store/unlock', [
            'event_public_id' => $fixture['event']->public_id,
            'code' => 'NOTHING',
        ])
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('reason', 'unknown_code');

        $this->hold($fixture, [0])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'access_code_required');
    }

    #[Test]
    public function a_code_belongs_to_the_account_that_made_it(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->window($fixture, presale: now()->subHour(), onSale: now()->addWeek());

        // Somebody else's MEMBERS opens somebody else's presale, and this one not at all.
        $other = $this->makeSellableEvent();
        $this->code($other, ['code' => 'MEMBERS']);

        $this->hold($fixture, [0], 'MEMBERS')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'unknown_code');
    }

    #[Test]
    public function the_screen_is_behind_the_permission_that_hands_out_promises(): void
    {
        $fixture = $this->makeSellableEvent();
        $doorman = $this->makeUser($fixture['tenant'], 'door');

        $this->actingAs($doorman)->getJson('/v1/access-codes')->assertForbidden();
        $this->actingAs($doorman)->postJson('/v1/access-codes', ['code' => 'MINE'])->assertForbidden();
    }

    #[Test]
    public function a_code_that_let_somebody_in_is_paused_rather_than_deleted(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->window($fixture, presale: now()->subHour(), onSale: now()->addWeek());
        $code = $this->code($fixture, ['code' => 'MEMBERS']);
        $owner = $this->makeUser($fixture['tenant']);

        $this->hold($fixture, [0], 'MEMBERS')->assertCreated();

        $this->actingAs($owner)->deleteJson('/v1/access-codes/'.$code->id)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'access_code_in_use');

        $this->actingAs($owner)->patchJson('/v1/access-codes/'.$code->id, ['status' => 'paused'])
            ->assertOk()
            ->assertJsonPath('status', 'paused')
            ->assertJsonPath('live', false);

        // Paused means paused: the next buyer is refused even inside the presale window.
        $this->newBrowser();
        $this->hold($fixture, [1], 'MEMBERS')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'code_not_live');
    }

    #[Test]
    public function the_panel_can_make_a_code_and_read_its_uses_back(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        $suggested = $this->actingAs($owner)->getJson('/v1/access-codes/suggest')->assertOk()->json('code');

        // Read out over a telephone: no O against 0, no I against 1.
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{8}$/', $suggested);

        $made = $this->actingAs($owner)->postJson('/v1/access-codes', [
            'code' => 'members',
            'label' => 'The mailing list',
            'opens' => 'presale',
            'max_uses' => 50,
        ])->assertCreated();

        $made->assertJsonPath('code', 'MEMBERS');
        $made->assertJsonPath('used_count', 0);

        // The same name twice is one code with two meanings, which is no meaning at all.
        $this->actingAs($owner)->postJson('/v1/access-codes', ['code' => 'MEMBERS'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'access_code_taken');
    }

    /* ------------------------------------------------------------------------------ helpers */

    private function window(array $fixture, ?\DateTimeInterface $presale, ?\DateTimeInterface $onSale): void
    {
        app(TenantContext::class)->runAs($fixture['tenant'], fn () => Event::whereKey($fixture['event']->id)
            ->update(['presale_starts_at' => $presale, 'on_sale_at' => $onSale]));
    }

    private function code(array $fixture, array $attributes): AccessCode
    {
        // `+` keeps the left operand's keys, so the caller's values go on the left.
        return app(TenantContext::class)->runAs($fixture['tenant'], fn () => AccessCode::create($attributes + [
            'tenant_id' => $fixture['tenant']->id,
            'event_id' => $fixture['event']->id,
            'opens' => 'presale',
        ]));
    }

    /** @param  list<int>  $seats */
    private function hold(array $fixture, array $seats, ?string $code = null)
    {
        return $this->postJson('http://northgate.test/_store/hold', array_filter([
            'event_public_id' => $fixture['event']->public_id,
            'seat_ids' => array_map(fn (int $i) => $fixture['seats'][$i]->id, $seats),
            'access_code' => $code,
        ]));
    }

    private function used(array $fixture, AccessCode $code): int
    {
        return app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => app(AccessCodes::class)->used($code->fresh())
        );
    }

    /** A different buyer, which on a hosted site means a different session. */
    private function newBrowser(): void
    {
        $this->app['session']->flush();
        $this->app['session']->regenerate();
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
