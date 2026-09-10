<?php

namespace Tests\Feature;

use App\Domain\Renewals\Renewals;
use App\Domain\Sites\SiteProvisioner;
use App\Models\Event;
use App\Models\EventPriceZone;
use App\Models\EventSeries;
use App\Models\MessageDelivery;
use App\Models\RenewalOffer;
use App\Models\RenewalRound;
use App\Models\SeasonBooking;
use App\Models\SeasonPass;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * First refusal on the same chairs, for the people who sat in them last season.
 *
 * The claim under test is a promise with a date on it: **until the deadline these seats belong to
 * the subscriber who had them, and after it they belong to whoever buys them first** — with no
 * sweep in between that somebody has to remember to run. So the tests are mostly about time and
 * about who can reach a seat at each moment, rather than about screens.
 *
 * The second claim is that accepting is not a new way of buying. It makes the ordinary hold and
 * hands the subscriber to the season checkout that already exists, so nothing about what a season
 * costs is worked out twice.
 */
class RenewalTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function last_seasons_subscribers_are_offered_the_same_chairs(): void
    {
        $last = $this->makeRun(2, 'Last season');
        $this->makeSite($last['tenant']);
        $next = $this->anotherRun($last, 'Next season');

        $this->buySeats($last, [0, 1], 'amina@example.test', 'Amina Farsi');
        $this->newBrowser();
        $this->buySeats($last, [4], 'bo@example.test', 'Bo Nilsson');

        $result = $this->openRound($last, $next);

        $this->assertSame(2, $result['offered']);
        $this->assertSame(3, $result['seats']);

        $this->inTenant($last, function () use ($last) {
            $amina = RenewalOffer::with('seats')->where('email', 'amina@example.test')->firstOrFail();

            // The same chairs, not a chair each: a subscription is a household's seats.
            $this->assertSame('offered', $amina->state);
            $this->assertEqualsCanonicalizing(
                [$last['seats'][0]->id, $last['seats'][1]->id],
                $amina->seats->pluck('seat_id')->all(),
            );
        });
    }

    #[Test]
    public function a_chair_under_an_offer_is_not_for_sale_to_anybody_else(): void
    {
        $last = $this->makeRun(2, 'Last season');
        $this->makeSite($last['tenant']);
        $next = $this->anotherRun($last, 'Next season');

        $this->buySeats($last, [0], 'amina@example.test', 'Amina Farsi');
        $this->assertSame('available', $this->stateOf($next, 0));

        $this->openRound($last, $next);

        // Held, in the plainest sense of the word: somebody has it at the moment, and will not
        // have it for ever.
        $this->assertSame('held', $this->stateOf($next, 0));

        $this->newBrowser();
        $this->holdOn($next, 0, [0])->assertStatus(409);
    }

    #[Test]
    public function the_chairs_come_back_on_the_stroke_of_the_deadline_with_nothing_swept(): void
    {
        $last = $this->makeRun(2, 'Last season');
        $this->makeSite($last['tenant']);
        $next = $this->anotherRun($last, 'Next season');

        $this->buySeats($last, [0], 'amina@example.test', 'Amina Farsi');
        $this->openRound($last, $next);

        $this->assertSame('held', $this->stateOf($next, 0));

        /*
         * The deadline passes, and that is all that happens.
         *
         * Moved on the row rather than by travelling the application's clock, because the promise
         * is kept by the *database's* clock — the same one a hold expires on — and a test that
         * travelled PHP's would be testing a clock nothing in this feature reads. Nothing is
         * closed, nothing is swept, and the round still says it is open.
         */
        $this->inTenant($last, fn () => RenewalRound::query()
            ->update(['deadline' => now()->subMinute()]));

        $this->assertSame('open', $this->inTenant($last, fn () => RenewalRound::firstOrFail()->state));
        $this->assertSame('available', $this->stateOf($next, 0));

        $this->newBrowser();
        $this->holdOn($next, 0, [0])->assertCreated();
    }

    #[Test]
    public function taking_them_hands_the_subscriber_to_the_season_checkout(): void
    {
        $last = $this->makeRun(2, 'Last season');
        $this->makeSite($last['tenant']);
        $next = $this->anotherRun($last, 'Next season');
        $pass = $this->pass($next);

        $this->buySeats($last, [0, 1], 'amina@example.test', 'Amina Farsi');
        $this->openRound($last, $next, $pass);

        $offer = $this->offerFor($last, 'amina@example.test');

        $this->newBrowser();
        $this->post($this->link($last, $offer).'/accept')->assertRedirect('/season/checkout');

        // The ordinary subscriber's session, with the seats already chosen — which is the whole
        // point: nothing about what a season costs is worked out a second time.
        $this->assertNotNull(session('seatmap_hold'));
        $this->assertSame($pass->id, session('seatmap_season'));
        $this->assertCount(2, (array) session('seatmap_season_nights'));

        $this->post('http://northgate.test/season/checkout', [
            'name' => 'Amina Farsi',
            'email' => 'amina@example.test',
            'gateway' => 'offline',
        ])->assertRedirect();

        $this->inTenant($last, function () {
            $this->assertSame('accepted', RenewalOffer::firstOrFail()->state);
            // Two nights, two seats: an ordinary subscription, bought the ordinary way.
            $this->assertSame(2, (int) SeasonBooking::firstOrFail()->nights);
            $this->assertSame(2, (int) SeasonBooking::firstOrFail()->seats);
        });
    }

    #[Test]
    public function saying_no_puts_the_chairs_back_at_once_rather_than_at_the_deadline(): void
    {
        $last = $this->makeRun(2, 'Last season');
        $this->makeSite($last['tenant']);
        $next = $this->anotherRun($last, 'Next season');

        $this->buySeats($last, [0], 'amina@example.test', 'Amina Farsi');
        $this->openRound($last, $next);

        $offer = $this->offerFor($last, 'amina@example.test');

        $this->newBrowser();
        $this->post($this->link($last, $offer).'/decline')->assertOk();

        // The seats a subscriber gives up early are exactly the seats a box office wants back
        // early, so declining does not wait for the date.
        $this->assertSame('available', $this->stateOf($next, 0));
        $this->assertSame('declined', $this->inTenant(
            $last,
            fn () => RenewalOffer::firstOrFail()->state
        ));
    }

    #[Test]
    public function somebody_elses_link_reaches_nothing(): void
    {
        $last = $this->makeRun(2, 'Last season');
        $this->makeSite($last['tenant']);
        $next = $this->anotherRun($last, 'Next season');

        $this->buySeats($last, [0], 'amina@example.test', 'Amina Farsi');
        $this->openRound($last, $next);

        $offer = $this->offerFor($last, 'amina@example.test');

        // A 404 rather than a refusal: "wrong token" would tell somebody guessing that this offer
        // exists, and an offer existing says that a named person subscribes to this theatre.
        $this->get('http://northgate.test/renewals/'.$offer->id.'/nonsense')->assertNotFound();
        $this->get('http://northgate.test/renewals/'.Str::uuid().'/nonsense')->assertNotFound();
    }

    #[Test]
    public function an_answer_is_given_once(): void
    {
        $last = $this->makeRun(2, 'Last season');
        $this->makeSite($last['tenant']);
        $next = $this->anotherRun($last, 'Next season');

        $this->buySeats($last, [0], 'amina@example.test', 'Amina Farsi');
        $this->openRound($last, $next);

        $offer = $this->offerFor($last, 'amina@example.test');
        $link = $this->link($last, $offer);

        $this->newBrowser();
        $this->post($link.'/decline')->assertOk();

        // Declining and then accepting would be a way to take back seats already on sale, which
        // somebody else may be paying for at this moment.
        $this->post($link.'/accept')->assertOk()->assertSee('already been answered', false);

        $this->assertSame('declined', $this->inTenant(
            $last,
            fn () => RenewalOffer::firstOrFail()->state
        ));
    }

    #[Test]
    public function closing_a_round_writes_down_who_never_answered(): void
    {
        $last = $this->makeRun(2, 'Last season');
        $this->makeSite($last['tenant']);
        $next = $this->anotherRun($last, 'Next season');

        $this->buySeats($last, [0], 'amina@example.test', 'Amina Farsi');
        $round = $this->openRound($last, $next)['round'];

        $owner = $this->makeUser($last['tenant']);

        $this->actingAs($owner)
            ->postJson("/v1/renewals/{$round->id}/close")
            ->assertOk()
            ->assertJsonPath('data.state', 'closed')
            ->assertJsonPath('data.counts.lapsed', 1);

        // The seats were back on sale at the deadline whether or not anybody pressed this. What is
        // written down is the difference between "said no" and "never replied", which is the
        // question the box office is asked in October.
        $this->assertSame('available', $this->stateOf($next, 0));
    }

    #[Test]
    public function two_rounds_cannot_offer_one_run_at_once(): void
    {
        $last = $this->makeRun(2, 'Last season');
        $this->makeSite($last['tenant']);
        $next = $this->anotherRun($last, 'Next season');

        $this->buySeats($last, [0], 'amina@example.test', 'Amina Farsi');
        $this->openRound($last, $next);

        $owner = $this->makeUser($last['tenant']);
        $pass = $this->pass($next);

        $this->actingAs($owner)
            ->postJson("/v1/series/{$next['series']->id}/renewals", [
                'from_series_id' => $last['series']->id,
                'season_pass_id' => $pass->id,
                'deadline' => now()->addDays(30)->toIso8601String(),
                'name' => 'A second go',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'renewal_round_open');
    }

    #[Test]
    public function the_invitation_is_sent_once_and_carries_a_link_of_its_own(): void
    {
        $last = $this->makeRun(2, 'Last season');
        $this->makeSite($last['tenant']);
        $next = $this->anotherRun($last, 'Next season');

        $this->buySeats($last, [0], 'amina@example.test', 'Amina Farsi');
        $round = $this->openRound($last, $next)['round'];

        $owner = $this->makeUser($last['tenant']);

        $this->actingAs($owner)->postJson("/v1/renewals/{$round->id}/invite")
            ->assertOk()->assertJsonPath('sent', 1);

        // Pressing it twice must not write to anybody twice.
        $this->actingAs($owner)->postJson("/v1/renewals/{$round->id}/invite")
            ->assertOk()->assertJsonPath('sent', 0);

        $this->inTenant($last, function () {
            $delivery = MessageDelivery::where('kind', 'season.renewal')->firstOrFail();
            $offer = RenewalOffer::firstOrFail();

            $this->assertSame('amina@example.test', $delivery->recipient);
            $this->assertSame('sent', $delivery->status);

            // The wording itself, rather than the 200-character preview the log keeps: what
            // matters is that the message somebody receives carries their own link, and the
            // preview is a first line rather than the letter.
            $written = app(\App\Domain\Messaging\MessageDispatcher::class)->render(
                'season.renewal',
                'email',
                'en',
                [
                    'buyer' => 'Amina Farsi',
                    'run' => 'Renewals for next season',
                    'seats' => 'A 1',
                    'deadline' => 'the fourteenth',
                    'site' => 'Northgate',
                    'link' => app(Renewals::class)->linkFor($offer, 'https://northgate.test'),
                ],
            );

            $this->assertStringContainsString('/renewals/'.$offer->id.'/', $written['body']);
        });
    }

    #[Test]
    public function the_screens_are_behind_the_permissions_they_belong_to(): void
    {
        $last = $this->makeRun(2, 'Last season');
        $this->makeSite($last['tenant']);
        $next = $this->anotherRun($last, 'Next season');
        $this->buySeats($last, [0], 'amina@example.test', 'Amina Farsi');
        $round = $this->openRound($last, $next)['round'];

        // The door can see events but may not decide who buys which chairs, nor write to anybody.
        $doorman = $this->makeUser($last['tenant'], 'door');

        $this->actingAs($doorman)->getJson("/v1/renewals/{$round->id}")->assertOk();
        $this->actingAs($doorman)->postJson("/v1/renewals/{$round->id}/invite")->assertForbidden();
        $this->actingAs($doorman)->postJson("/v1/renewals/{$round->id}/close")->assertForbidden();
    }

    #[Test]
    public function a_chair_that_is_not_in_the_new_plan_is_counted_rather_than_swallowed(): void
    {
        $last = $this->makeRun(2, 'Last season');
        $this->makeSite($last['tenant']);
        // A new run in the same venue but on a different published plan: the old chairs have no
        // counterpart, and an organiser who is not told that will hear it from the subscribers.
        $other = $this->makeSellableEvent(tenant: $last['tenant']);
        $next = $this->anotherRun($last, 'Next season', $other['event']);

        $this->buySeats($last, [0], 'amina@example.test', 'Amina Farsi');

        $result = $this->openRound($last, $next);

        $this->assertSame(0, $result['offered']);
        $this->assertSame(1, $result['skipped']['no_such_seat']);
    }

    /* ------------------------------------------------------------------------------ helpers */

    /** @return array{round: RenewalRound, offered: int, seats: int, skipped: array<string, int>} */
    private function openRound(array $last, array $next, ?SeasonPass $pass = null): array
    {
        $pass ??= $this->pass($next);

        return $this->inTenant($last, fn () => app(Renewals::class)->open(
            $last['series'],
            $next['series'],
            $pass,
            now()->addDays(30),
            'Renewals for next season',
        ));
    }

    private function offerFor(array $run, string $email): RenewalOffer
    {
        return $this->inTenant(
            $run,
            fn () => RenewalOffer::where('email', $email)->firstOrFail()
        );
    }

    private function link(array $run, RenewalOffer $offer): string
    {
        $token = $this->inTenant($run, fn () => app(Renewals::class)->tokenFor($offer));

        return 'http://northgate.test/renewals/'.$offer->id.'/'.$token;
    }

    /** What the public sees about one seat on the first night of a run. */
    private function stateOf(array $run, int $seat): string
    {
        return $this->inTenant($run, function () use ($run, $seat) {
            $night = Event::findOrFail($run['nights'][0]->id);
            $seatId = $run['seats'][$seat]->id;

            foreach (app(\App\Domain\Availability\AvailabilityService::class)->forEvent($night) as $row) {
                if ($row['seat_id'] === $seatId) {
                    return $row['state'];
                }
            }

            return 'missing';
        });
    }

    /** @param  list<int>  $seats */
    private function holdOn(array $run, int $night, array $seats)
    {
        return $this->postJson('http://northgate.test/_store/hold', [
            'event_public_id' => $run['nights'][$night]->public_id,
            'seat_ids' => array_map(fn (int $i) => $run['seats'][$i]->id, $seats),
        ]);
    }

    /** @param  list<int>  $seats */
    private function buySeats(array $run, array $seats, string $email, string $name): void
    {
        $this->holdOn($run, 0, $seats)->assertCreated();

        $this->post('http://northgate.test/checkout', [
            'name' => $name,
            'email' => $email,
            'gateway' => 'offline',
        ])->assertRedirect();
    }

    private function newBrowser(): void
    {
        $this->app['session']->flush();
        $this->app['session']->regenerate();
    }

    /** A run of `$nights` nights on one map, in one series. */
    private function makeRun(int $nights, string $name): array
    {
        $fixture = $this->makeSellableEvent(amount: 2500);

        return app(TenantContext::class)->runAs($fixture['tenant'], function () use ($fixture, $nights, $name) {
            $series = EventSeries::create([
                'tenant_id' => $fixture['tenant']->id,
                'name' => $name,
                'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            ]);

            $first = $fixture['event'];
            $first->forceFill(['series_id' => $series->id, 'name' => $name.' night 1'])->save();

            $made = [$first];

            for ($n = 2; $n <= $nights; $n++) {
                $made[] = $this->night($first, $series, $name.' night '.$n, $n);
            }

            return $fixture + ['series' => $series, 'nights' => $made];
        });
    }

    /** A second run, on the same plan as the first unless another event is handed in. */
    private function anotherRun(array $last, string $name, ?Event $on = null): array
    {
        return app(TenantContext::class)->runAs($last['tenant'], function () use ($last, $name, $on) {
            $series = EventSeries::create([
                'tenant_id' => $last['tenant']->id,
                'name' => $name,
                'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            ]);

            $template = $on ?? $last['nights'][0];
            $nights = [
                $this->night($template, $series, $name.' night 1', 20),
                $this->night($template, $series, $name.' night 2', 21),
            ];

            // array_merge, not `+`: the left operand of `+` wins, so a run built from last
            // season's fixture would quietly keep last season's series and nights.
            return array_merge($last, ['series' => $series, 'nights' => $nights]);
        });
    }

    private function night(Event $like, EventSeries $series, string $name, int $weeks): Event
    {
        $night = Event::create([
            'venue_id' => $like->venue_id,
            'series_id' => $series->id,
            'seat_map_id' => $like->seat_map_id,
            'seat_map_version_id' => $like->seat_map_version_id,
            'public_id' => 'evt_'.Str::lower(Str::random(20)),
            'name' => $name,
            'status' => 'published',
            'starts_at' => now()->addWeeks($weeks),
            'timezone' => 'Europe/Berlin',
            'currency' => 'EUR',
        ]);

        foreach (['standard' => 2500, 'standing' => 1250] as $key => $amount) {
            EventPriceZone::create([
                'event_id' => $night->id,
                'key' => $key,
                'name' => ucfirst($key),
                'amount' => $amount,
            ]);
        }

        return $night;
    }

    private function pass(array $run): SeasonPass
    {
        return $this->inTenant($run, fn () => SeasonPass::create([
            'tenant_id' => $run['tenant']->id,
            'series_id' => $run['series']->id,
            // Named uniquely: one account may not have two passes of the same name on one run,
            // and a test that makes two is testing the name and not the renewal.
            'name' => 'Full season '.Str::lower(Str::random(5)),
            'kind' => 'all',
            'discount_kind' => 'percent',
            'discount_value' => 20,
            'currency' => 'EUR',
        ]));
    }

    private function inTenant(array $run, callable $work)
    {
        return app(TenantContext::class)->runAs($run['tenant'], $work);
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
