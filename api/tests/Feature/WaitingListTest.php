<?php

namespace Tests\Feature;

use App\Domain\Sites\SiteProvisioner;
use App\Domain\Waitlist\WaitingList;
use App\Models\MessageDelivery;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\WaitingListEntry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * The queue for a sold-out night.
 *
 * The rules worth guarding are the fair ones: first asked, first told; nobody told about a place
 * that is already promised to somebody whose turn is still open; and nobody thrown off the list
 * for being asleep when their turn came round.
 */
class WaitingListTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function the_form_appears_only_when_there_is_a_queue_worth_joining(): void
    {
        // Two seats, both sold: the night is full.
        $fixture = $this->makeSellableEvent(rows: 1, perRow: 2);
        $this->makeSite($fixture['tenant']);

        $this->get('http://northgate.test/events/'.$fixture['event']->public_id)
            ->assertOk()
            ->assertDontSee('Tell me when a seat is free');

        $this->sellOut($fixture);

        $this->get('http://northgate.test/events/'.$fixture['event']->public_id)
            ->assertOk()
            ->assertSee('Tell me when a seat is free');
    }

    #[Test]
    public function asking_twice_does_not_take_two_places_in_the_queue(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);

        foreach ([1, 2] as $quantity) {
            $this->post('http://northgate.test/events/'.$fixture['event']->public_id.'/waiting-list', [
                'name' => 'Amina Farsi',
                'email' => 'AMINA@example.test',
                'quantity' => $quantity,
            ])->assertRedirect();
        }

        $entries = app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => WaitingListEntry::get()
        );

        $this->assertCount(1, $entries, 'Asking twice is not asking harder.');
        // Lower-cased on the way in, or the same person is two people.
        $this->assertSame('amina@example.test', $entries[0]->email);
        $this->assertSame(1, $entries[0]->quantity, 'And they keep their original place.');
    }

    #[Test]
    public function people_are_told_in_the_order_they_asked(): void
    {
        Mail::fake();

        $fixture = $this->makeSellableEvent(rows: 1, perRow: 2);
        $site = $this->makeSite($fixture['tenant']);

        $this->sellOut($fixture);

        foreach (['first', 'second', 'third'] as $index => $who) {
            $this->join($fixture, $who.'@example.test', 1);
            $this->travel(60)->seconds();
        }

        // One seat comes back.
        $this->freeOneSeat($fixture);

        $told = app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => app(WaitingList::class)->notify($fixture['event'], $site)
        );

        $this->assertSame(1, $told, 'One place, one person.');

        $states = app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => WaitingListEntry::orderBy('created_at')->pluck('status', 'email')->all()
        );

        $this->assertSame([
            'first@example.test' => 'notified',
            'second@example.test' => 'waiting',
            'third@example.test' => 'waiting',
        ], $states);

        $delivery = app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => MessageDelivery::where('kind', 'waitlist.available')->firstOrFail()
        );

        $this->assertSame('first@example.test', $delivery->recipient);
    }

    #[Test]
    public function a_place_already_promised_is_not_offered_twice(): void
    {
        Mail::fake();

        $fixture = $this->makeSellableEvent(rows: 1, perRow: 2);
        $site = $this->makeSite($fixture['tenant']);

        $this->sellOut($fixture);
        $this->join($fixture, 'first@example.test', 1);
        $this->travel(60)->seconds();
        $this->join($fixture, 'second@example.test', 1);

        $this->freeOneSeat($fixture);

        app(TenantContext::class)->runAs($fixture['tenant'], function () use ($fixture, $site) {
            $list = app(WaitingList::class);

            $this->assertSame(1, $list->notify($fixture['event'], $site));
            // Run again a moment later: the seat is still free, but it is spoken for.
            $this->assertSame(0, $list->notify($fixture['event'], $site));
        });
    }

    #[Test]
    public function somebody_who_misses_their_turn_stays_on_the_list(): void
    {
        Mail::fake();

        $fixture = $this->makeSellableEvent(rows: 1, perRow: 2);
        $site = $this->makeSite($fixture['tenant']);

        $this->sellOut($fixture);
        $this->join($fixture, 'asleep@example.test', 1);
        $this->travel(60)->seconds();
        $this->join($fixture, 'awake@example.test', 1);

        $this->freeOneSeat($fixture);

        app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => app(WaitingList::class)->notify($fixture['event'], $site)
        );

        // Their window runs out without them buying anything.
        $this->travel(WaitingList::CLAIM_MINUTES + 5)->minutes();

        app(TenantContext::class)->runAs($fixture['tenant'], function () use ($fixture, $site) {
            $this->assertSame(1, app(WaitingList::class)->notify($fixture['event'], $site),
                'The next person gets a turn.');

            /*
             * And the one who slept through it is back in the queue.
             *
             * This assertion used to read `notified`, which looked like "still on the list" and was
             * really "stuck": the notifier reads only `waiting` rows, so that person would never
             * have been told again. Their turn is over, so they are waiting again — with the turn
             * they had counted against them, so the next release goes to somebody who has had none.
             */
            $asleep = WaitingListEntry::where('email', 'asleep@example.test')->firstOrFail();

            $this->assertSame('waiting', $asleep->status);
            $this->assertSame(1, $asleep->times_told);
        });
    }

    #[Test]
    public function somebody_who_slept_through_one_turn_gets_another(): void
    {
        Mail::fake();

        $fixture = $this->makeSellableEvent(rows: 1, perRow: 2);
        $site = $this->makeSite($fixture['tenant']);

        $this->sellOut($fixture);
        $this->join($fixture, 'asleep@example.test', 1);
        $this->freeOneSeat($fixture);

        app(TenantContext::class)->runAs($fixture['tenant'], function () use ($fixture, $site) {
            $this->assertSame(1, app(WaitingList::class)->notify($fixture['event'], $site));
        });

        $this->travel(WaitingList::CLAIM_MINUTES + 5)->minutes();

        // Nobody else is on the list, and the seat is still free. They are the next person again.
        app(TenantContext::class)->runAs($fixture['tenant'], function () use ($fixture, $site) {
            $this->assertSame(1, app(WaitingList::class)->notify($fixture['event'], $site),
                'A second turn, which is what "comes round again" has to mean.');

            $this->assertSame(2, WaitingListEntry::where('email', 'asleep@example.test')
                ->value('times_told'));
        });
    }

    #[Test]
    public function after_three_unanswered_turns_the_platform_stops_writing(): void
    {
        Mail::fake();

        $fixture = $this->makeSellableEvent(rows: 1, perRow: 2);
        $site = $this->makeSite($fixture['tenant']);

        $this->sellOut($fixture);
        $this->join($fixture, 'asleep@example.test', 1);
        $this->freeOneSeat($fixture);

        // Three turns, each slept through.
        for ($turn = 0; $turn < WaitingList::MAX_TURNS; $turn++) {
            app(TenantContext::class)->runAs($fixture['tenant'], function () use ($fixture, $site) {
                app(WaitingList::class)->notify($fixture['event'], $site);
            });

            $this->travel(WaitingList::CLAIM_MINUTES + 5)->minutes();
        }

        app(TenantContext::class)->runAs($fixture['tenant'], function () use ($fixture, $site) {
            $this->assertSame(0, app(WaitingList::class)->notify($fixture['event'], $site),
                'An email every two hours until the doors open is not a waiting list.');

            $entry = WaitingListEntry::where('email', 'asleep@example.test')->firstOrFail();

            // Quiet, not gone: the row is still there and the organiser can see exactly why.
            $this->assertSame('lapsed', $entry->status);
            $this->assertNotNull($entry->lapsed_at);
        });
    }

    #[Test]
    public function asking_again_after_going_quiet_puts_somebody_back_in_the_queue(): void
    {
        Mail::fake();

        $fixture = $this->makeSellableEvent(rows: 1, perRow: 2);
        $site = $this->makeSite($fixture['tenant']);

        $this->sellOut($fixture);
        $this->join($fixture, 'asleep@example.test', 1);
        $this->freeOneSeat($fixture);

        for ($turn = 0; $turn < WaitingList::MAX_TURNS; $turn++) {
            app(TenantContext::class)->runAs($fixture['tenant'], function () use ($fixture, $site) {
                app(WaitingList::class)->notify($fixture['event'], $site);
            });

            $this->travel(WaitingList::CLAIM_MINUTES + 5)->minutes();
        }

        app(TenantContext::class)->runAs($fixture['tenant'], fn () => app(WaitingList::class)
            ->notify($fixture['event'], $site));

        // They come back of their own accord, which is a new request and not a continuation.
        $this->join($fixture, 'asleep@example.test', 1);

        app(TenantContext::class)->runAs($fixture['tenant'], function () use ($fixture, $site) {
            $entry = WaitingListEntry::where('email', 'asleep@example.test')->firstOrFail();

            $this->assertSame('waiting', $entry->status);
            $this->assertSame(0, $entry->times_told, 'Their turns are back.');

            $this->assertSame(1, app(WaitingList::class)->notify($fixture['event'], $site));
        });
    }

    #[Test]
    public function the_link_in_the_message_takes_somebody_off_the_list(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);

        $this->join($fixture, 'amina@example.test', 2);

        $entry = app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => WaitingListEntry::firstOrFail()
        );

        $this->get('http://northgate.test/waiting-list/'.$entry->token.'/leave')
            ->assertOk()
            ->assertSee('off the list', escape: false);

        $this->assertSame('left', $entry->fresh()->status);

        // Safe to repeat: a mail client that prefetches the link must not break it.
        $this->get('http://northgate.test/waiting-list/'.$entry->token.'/leave')->assertOk();

        // An unknown token is not a hint about which tokens exist.
        $this->get('http://northgate.test/waiting-list/nonsense/leave')->assertNotFound();
    }

    #[Test]
    public function the_organiser_sees_the_queue_and_what_there_is_to_offer(): void
    {
        $fixture = $this->makeSellableEvent(rows: 1, perRow: 2);
        $owner = $this->makeUser($fixture['tenant']);
        $this->makeSite($fixture['tenant']);

        $this->join($fixture, 'amina@example.test', 3);

        $body = $this->actingAs($owner)
            ->getJson("/v1/events/{$fixture['event']->id}/waiting-list")
            ->assertOk()
            ->json();

        $this->assertSame(1, $body['summary']['waiting']);
        $this->assertSame(3, $body['summary']['waiting_places'], 'One person, three places wanted.');
        $this->assertSame(2, $body['summary']['free_places']);
        $this->assertSame('amina@example.test', $body['data'][0]['email']);
    }

    #[Test]
    public function writing_to_the_queue_needs_the_permission_to_write_to_people(): void
    {
        $fixture = $this->makeSellableEvent();
        $box = $this->makeUser($fixture['tenant'], 'box_office');

        // A box office may read the list — it is customer data they already see — and may not
        // write to everybody on it.
        $this->actingAs($box)->getJson("/v1/events/{$fixture['event']->id}/waiting-list")->assertOk();
        $this->actingAs($box)
            ->postJson("/v1/events/{$fixture['event']->id}/waiting-list/notify")
            ->assertForbidden();
    }

    /* ------------------------------------------------------------------------------ helpers */

    #[Test]
    public function somebody_who_buys_comes_off_the_queue_without_saying_so(): void
    {
        Mail::fake();

        $fixture = $this->makeSellableEvent(rows: 1, perRow: 2);
        $site = $this->makeSite($fixture['tenant']);

        $this->sellOut($fixture);
        $this->join($fixture, 'patient@example.test', 1);
        $this->freeOneSeat($fixture);

        app(TenantContext::class)->runAs($fixture['tenant'], fn () => app(WaitingList::class)
            ->notify($fixture['event'], $site));

        // They follow the link and buy the seat that came back, under the same address.
        $this->buy($fixture, 'patient@example.test', 0);

        app(TenantContext::class)->runAs($fixture['tenant'], function () {
            $entry = WaitingListEntry::where('email', 'patient@example.test')->firstOrFail();

            /*
             * `converted` used to be a status the API would let an organiser filter by and that
             * nothing anywhere ever set, so "who on my list actually bought" was an empty page
             * every time.
             */
            $this->assertSame('converted', $entry->status);
            $this->assertNotNull($entry->converted_at);
        });
    }

    #[Test]
    public function somebody_who_bought_is_never_told_about_another_seat(): void
    {
        Mail::fake();

        $fixture = $this->makeSellableEvent(rows: 1, perRow: 3);
        $site = $this->makeSite($fixture['tenant']);

        $this->sellOut($fixture);
        $this->join($fixture, 'patient@example.test', 1);
        $this->freeOneSeat($fixture);

        app(TenantContext::class)->runAs($fixture['tenant'], fn () => app(WaitingList::class)
            ->notify($fixture['event'], $site));

        $this->buy($fixture, 'patient@example.test', 0);

        // Another seat comes back, and they are the only name on the list.
        app(TenantContext::class)->runAs($fixture['tenant'], function () use ($fixture, $site) {
            app(\App\Domain\Orders\OrderService::class)->refund(
                \App\Models\ExternalOrder::where('status', 'confirmed')
                    ->orderBy('created_at')->firstOrFail(),
                [$fixture['seats'][1]->id],
                'another change of plan',
            );

            $this->assertSame(0, app(WaitingList::class)->notify($fixture['event'], $site),
                'They have their seat. Writing to them again is writing to the wrong person.');
        });
    }

    #[Test]
    public function the_organiser_can_see_who_bought_and_who_went_quiet(): void
    {
        Mail::fake();

        $fixture = $this->makeSellableEvent(rows: 1, perRow: 2);
        $site = $this->makeSite($fixture['tenant']);
        $owner = $this->makeUser($fixture['tenant']);

        $this->sellOut($fixture);
        $this->join($fixture, 'patient@example.test', 1);
        $this->freeOneSeat($fixture);

        app(TenantContext::class)->runAs($fixture['tenant'], fn () => app(WaitingList::class)
            ->notify($fixture['event'], $site));

        $this->buy($fixture, 'patient@example.test', 0);

        $body = $this->actingAs($owner)
            ->getJson('/v1/events/'.$fixture['event']->id.'/waiting-list')
            ->assertOk()
            ->json();

        $this->assertSame(1, $body['summary']['converted']);
        $this->assertSame(0, $body['summary']['waiting']);
        $this->assertSame(1, $body['data'][0]['times_told']);
        $this->assertNotNull($body['data'][0]['converted_at']);

        // And the filter that answered nothing now answers.
        $filtered = $this->actingAs($owner)
            ->getJson('/v1/events/'.$fixture['event']->id.'/waiting-list?status=converted')
            ->assertOk()
            ->json();

        $this->assertCount(1, $filtered['data']);
    }

    #[Test]
    public function the_scheduled_round_reaches_a_queue_where_everybody_has_already_been_told(): void
    {
        Mail::fake();

        $fixture = $this->makeSellableEvent(rows: 1, perRow: 2);
        $site = $this->makeSite($fixture['tenant']);

        $this->sellOut($fixture);
        $this->join($fixture, 'asleep@example.test', 1);
        $this->freeOneSeat($fixture);

        app(TenantContext::class)->runAs($fixture['tenant'], fn () => app(WaitingList::class)
            ->notify($fixture['event'], $site));

        $this->travel(WaitingList::CLAIM_MINUTES + 5)->minutes();

        /*
         * Nobody on this list is `waiting` — the only person on it is stuck at `notified`.
         *
         * A round that looked only for `waiting` rows would find no event to examine and leave
         * them there for ever, which is the shape of the original bug.
         */
        $this->artisan('waitlist:notify')->assertSuccessful();

        app(TenantContext::class)->runAs($fixture['tenant'], function () {
            $entry = WaitingListEntry::where('email', 'asleep@example.test')->firstOrFail();

            $this->assertSame('notified', $entry->status, 'Told again, on a fresh turn.');
            $this->assertSame(2, $entry->times_told);
        });
    }

    /** They follow the link and buy the seat, under the address they joined the list with. */
    private function buy(array $fixture, string $email, int $seat): void
    {
        $this->postJson('http://northgate.test/_store/hold', [
            'event_public_id' => $fixture['event']->public_id,
            'seat_ids' => [$fixture['seats'][$seat]->id],
        ])->assertCreated();

        $this->post('http://northgate.test/checkout', [
            'name' => explode('@', $email)[0],
            'email' => $email,
            'gateway' => 'offline',
        ])->assertRedirect();
    }

    private function join(array $fixture, string $email, int $quantity): void
    {
        $this->post('http://northgate.test/events/'.$fixture['event']->public_id.'/waiting-list', [
            'name' => explode('@', $email)[0],
            'email' => $email,
            'quantity' => $quantity,
        ])->assertRedirect();
    }

    private function sellOut(array $fixture): void
    {
        $this->postJson('http://northgate.test/_store/hold', [
            'event_public_id' => $fixture['event']->public_id,
            'seat_ids' => collect($fixture['seats'])->pluck('id')->all(),
        ])->assertCreated();

        $this->post('http://northgate.test/checkout', [
            'name' => 'Early buyer',
            'email' => 'early@example.test',
            'gateway' => 'offline',
        ])->assertRedirect();
    }

    /** A refund puts one seat back on sale, which is the commonest way a queue gets its turn. */
    private function freeOneSeat(array $fixture): void
    {
        app(TenantContext::class)->runAs($fixture['tenant'], function () use ($fixture) {
            $order = \App\Models\ExternalOrder::orderByDesc('created_at')->firstOrFail();

            app(\App\Domain\Orders\OrderService::class)->refund(
                $order,
                [$fixture['seats'][0]->id],
                'a change of plan',
            );
        });
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
