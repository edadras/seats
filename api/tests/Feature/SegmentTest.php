<?php

namespace Tests\Feature;

use App\Domain\Audience\Segments;
use App\Models\Event;
use App\Models\EventPriceZone;
use App\Models\MessageDelivery;
use App\Models\Segment;
use App\Models\Ticket;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * "Everybody who came last season and has not booked this one."
 *
 * That sentence is the whole feature, and the first test is exactly it. The rest pin the things
 * that make a saved audience safe to send to: that it holds rules and never a list of people, that
 * it never hands those people back through the API, that a clause nobody can read is dropped
 * rather than obeyed, and that money is only ever compared inside one currency.
 */
class SegmentTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    private array $clients = [];

    /* ------------------------------------------------------------------------ the sentence */

    #[Test]
    public function came_last_season_and_has_not_booked_this_one(): void
    {
        $fixture = $this->makeSellableEvent(rows: 4, perRow: 8);
        $thisSeason = $this->anotherNight($fixture, 'Hamlet');

        // Dana came last season and has not booked. Amir came last season and has booked.
        $this->sell($fixture, ['name' => 'Dana', 'email' => 'dana@example.test'], [0]);
        $this->sell($fixture, ['name' => 'Amir', 'email' => 'amir@example.test'], [1]);
        $this->sell($fixture, ['name' => 'Amir', 'email' => 'amir@example.test'], [2], $thisSeason);

        $people = $this->resolve($fixture, [
            'bought_events' => [$fixture['event']->id],
            'not_bought_events' => [$thisSeason->id],
        ]);

        $this->assertSame(['dana@example.test'], $people);
    }

    /* -------------------------------------------------------------------------- the clauses */

    #[Test]
    public function a_category_is_an_audience_whichever_night_it_was(): void
    {
        $fixture = $this->makeSellableEvent(rows: 4, perRow: 8);
        $opera = $this->anotherNight($fixture, 'Tosca', 'opera');

        $this->sell($fixture, ['name' => 'Dana', 'email' => 'dana@example.test'], [0]);
        $this->sell($fixture, ['name' => 'Ines', 'email' => 'ines@example.test'], [1], $opera);

        $this->assertSame(['ines@example.test'], $this->resolve($fixture, ['categories' => ['opera']]));
    }

    #[Test]
    public function the_regulars_are_the_people_who_came_back(): void
    {
        $fixture = $this->makeSellableEvent(rows: 4, perRow: 8);
        $second = $this->anotherNight($fixture, 'Hamlet');

        $this->sell($fixture, ['name' => 'Dana', 'email' => 'dana@example.test'], [0]);
        $this->sell($fixture, ['name' => 'Dana', 'email' => 'dana@example.test'], [1], $second);
        $this->sell($fixture, ['name' => 'Once', 'email' => 'once@example.test'], [2]);

        $this->assertSame(['dana@example.test'], $this->resolve($fixture, ['min_orders' => 2]));
    }

    #[Test]
    public function the_people_who_actually_turned_up_are_not_the_people_who_bought(): void
    {
        $fixture = $this->makeSellableEvent(rows: 4, perRow: 8);

        $this->sell($fixture, ['name' => 'Came', 'email' => 'came@example.test'], [0]);
        $this->sell($fixture, ['name' => 'Stayed in', 'email' => 'stayed@example.test'], [1]);

        // One of them was scanned at the door.
        app(TenantContext::class)->runAs($fixture['tenant'], function () {
            $ticket = Ticket::whereHas(
                'allocation.order',
                fn ($query) => $query->whereRaw("buyer->>'email' = ?", ['came@example.test'])
            )->firstOrFail();

            $ticket->forceFill(['status' => 'used', 'used_at' => now()])->save();
        });

        $this->assertSame(['came@example.test'], $this->resolve($fixture, ['attended' => true]));
    }

    #[Test]
    public function until_means_the_whole_of_that_day(): void
    {
        $fixture = $this->makeSellableEvent(rows: 4, perRow: 8);

        $this->sell($fixture, ['name' => 'Dana', 'email' => 'dana@example.test'], [0]);

        app(TenantContext::class)->runAs($fixture['tenant'], fn () => \App\Models\ExternalOrder::query()
            ->update(['confirmed_at' => now()->setTime(21, 40)]));

        // A booking paid for at twenty to ten belongs to that day, not to the next one.
        $this->assertSame(
            ['dana@example.test'],
            $this->resolve($fixture, ['until' => now()->toDateString()]),
        );

        $this->assertSame([], $this->resolve($fixture, ['until' => now()->subDay()->toDateString()]));
    }

    #[Test]
    public function money_is_only_ever_compared_inside_one_currency(): void
    {
        $fixture = $this->makeSellableEvent(rows: 4, perRow: 8, amount: 2500);
        $owner = $this->makeUser($fixture['tenant']);

        $this->sell($fixture, ['name' => 'Dana', 'email' => 'dana@example.test'], [0, 1, 2]);
        $this->sell($fixture, ['name' => 'Small', 'email' => 'small@example.test'], [3]);

        $this->assertSame(
            ['dana@example.test'],
            $this->resolve($fixture, ['min_spend' => 5000, 'currency' => 'EUR']),
        );

        // An account selling in two currencies has two answers to "spent more than a hundred", and
        // adding them would be a segment whose whole membership is an arithmetic mistake.
        $this->actingAs($owner)->postJson('/v1/segments', [
            'name' => 'Big spenders',
            'rules' => ['min_spend' => 5000],
        ])->assertStatus(422)->assertJsonPath('error.code', 'spend_needs_currency');
    }

    /* ------------------------------------------------------------------------- the endpoint */

    #[Test]
    public function a_saved_audience_says_how_many_and_never_who(): void
    {
        $fixture = $this->makeSellableEvent(rows: 4, perRow: 8);
        $owner = $this->makeUser($fixture['tenant']);

        $this->sell($fixture, ['name' => 'Dana', 'email' => 'dana@example.test'], [0]);
        $this->sell($fixture, ['name' => 'Amir', 'email' => 'amir@example.test'], [1]);

        $created = $this->actingAs($owner)->postJson('/v1/segments', [
            'name' => 'Everybody so far',
            'description' => 'The whole list',
            'rules' => ['bought_events' => [$fixture['event']->id]],
        ])->assertCreated()->json();

        $this->assertSame(2, $created['people']);
        // The rules come back as names, so a screen can say what a list means without fetching
        // every event to find out.
        $this->assertSame(['Opening night'], $created['explained']['bought_events']);

        // Nowhere in any of these answers is there an address. A segment is not a second customer
        // directory, and a screen that listed its people would be a way to walk out with the list.
        $body = json_encode([
            $created,
            $this->actingAs($owner)->getJson('/v1/segments')->assertOk()->json(),
            $this->actingAs($owner)->getJson('/v1/segments/'.$created['id'])->assertOk()->json(),
            $this->actingAs($owner)->postJson('/v1/segments/preview', [
                'rules' => ['bought_events' => [$fixture['event']->id]],
            ])->assertOk()->json(),
        ]);

        $this->assertStringNotContainsString('dana@example.test', $body);
        $this->assertStringNotContainsString('amir@example.test', $body);
    }

    #[Test]
    public function a_clause_nobody_can_read_is_dropped_rather_than_obeyed(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        $saved = $this->actingAs($owner)->postJson('/v1/segments', [
            'name' => 'From a newer panel',
            'rules' => ['min_orders' => 2, 'lives_in' => 'Berlin'],
        ])->assertCreated()->json();

        // Kept: what this version understands. Dropped: what it does not, so a stored rule that
        // nothing applies cannot quietly mean something wider than it says.
        $this->assertSame(['min_orders' => 2], $saved['rules']);
    }

    #[Test]
    public function two_lists_cannot_share_a_name(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        $this->actingAs($owner)->postJson('/v1/segments', ['name' => 'Christmas'])->assertCreated();
        $this->actingAs($owner)->postJson('/v1/segments', ['name' => 'Christmas'])->assertStatus(422);

        // Another account's list of the same name is another account's business.
        $other = $this->makeSellableEvent($this->makeTenant('Somebody else'));
        $this->actingAs($this->makeUser($other['tenant']))
            ->postJson('/v1/segments', ['name' => 'Christmas'])->assertCreated();
    }

    #[Test]
    public function another_account_s_audience_is_not_one_this_account_can_open(): void
    {
        $mine = $this->makeSellableEvent($this->makeTenant('Mine'));
        $theirs = $this->makeSellableEvent($this->makeTenant('Theirs'));

        $id = $this->actingAs($this->makeUser($theirs['tenant']))
            ->postJson('/v1/segments', ['name' => 'Theirs'])->assertCreated()->json('id');

        $this->actingAs($this->makeUser($mine['tenant']))
            ->getJson('/v1/segments/'.$id)->assertStatus(404);
    }

    /* --------------------------------------------------------------------- and the sending */

    #[Test]
    public function an_announcement_addressed_to_a_saved_audience_reaches_exactly_it(): void
    {
        $fixture = $this->makeSellableEvent(rows: 4, perRow: 8);
        $thisSeason = $this->anotherNight($fixture, 'Hamlet');
        $owner = $this->makeUser($fixture['tenant']);

        $this->sell($fixture, ['name' => 'Dana', 'email' => 'dana@example.test'], [0]);
        $this->sell($fixture, ['name' => 'Amir', 'email' => 'amir@example.test'], [1]);
        $this->sell($fixture, ['name' => 'Amir', 'email' => 'amir@example.test'], [2], $thisSeason);
        $this->agreed($fixture, 'dana@example.test', 'amir@example.test');

        $segment = $this->actingAs($owner)->postJson('/v1/segments', [
            'name' => 'Came last season, has not booked',
            'rules' => [
                'bought_events' => [$fixture['event']->id],
                'not_bought_events' => [$thisSeason->id],
            ],
        ])->assertCreated()->json();

        $this->assertSame(1, $segment['people']);

        $reach = $this->actingAs($owner)->getJson(
            '/v1/messaging/announcements/audience?channels[]=email&segment_id='.$segment['id']
        )->assertOk()->json();

        $this->assertSame(1, $reach['people']);

        $sent = $this->actingAs($owner)->postJson('/v1/messaging/announcements', [
            'segment_id' => $segment['id'],
            'channels' => ['email'],
            'subject' => 'We are back',
            'body' => 'Hello {buyer}, the new season opens in March.',
        ])->assertCreated()->json();

        $this->assertSame('segment', $sent['audience']);
        $this->assertSame('Came last season, has not booked', $sent['segment']);

        app(TenantContext::class)->runAs($fixture['tenant'], function () {
            $deliveries = MessageDelivery::where('kind', 'announcement')->get();

            $this->assertSame(['dana@example.test'], $deliveries->pluck('recipient')->all());
        });
    }

    #[Test]
    public function an_unknown_audience_is_refused_rather_than_widened(): void
    {
        $fixture = $this->makeSellableEvent(rows: 4, perRow: 8);
        $owner = $this->makeUser($fixture['tenant']);

        $this->sell($fixture, ['name' => 'Dana', 'email' => 'dana@example.test'], [0]);

        /*
         * The one wrong answer that cannot be taken back.
         *
         * Falling back to "everybody who ever bought" because an id did not resolve would send a
         * mailing to the whole account, and no amount of apologising unsends it.
         */
        $this->actingAs($owner)->postJson('/v1/messaging/announcements', [
            'segment_id' => (string) Str::uuid(),
            'channels' => ['email'],
            'body' => 'Hello',
        ])->assertStatus(422)->assertJsonPath('error.code', 'unknown_segment');

        app(TenantContext::class)->runAs($fixture['tenant'], fn () => $this->assertSame(
            0, MessageDelivery::where('kind', 'announcement')->count()
        ));
    }

    #[Test]
    public function deleting_a_list_does_not_delete_what_was_said_to_it(): void
    {
        $fixture = $this->makeSellableEvent(rows: 4, perRow: 8);
        $owner = $this->makeUser($fixture['tenant']);

        $this->sell($fixture, ['name' => 'Dana', 'email' => 'dana@example.test'], [0]);
        $this->agreed($fixture, 'dana@example.test');

        $segment = $this->actingAs($owner)->postJson('/v1/segments', [
            'name' => 'Everybody',
            'rules' => ['bought_events' => [$fixture['event']->id]],
        ])->assertCreated()->json();

        $this->actingAs($owner)->postJson('/v1/messaging/announcements', [
            'segment_id' => $segment['id'],
            'channels' => ['email'],
            'body' => 'Hello',
        ])->assertCreated();

        $this->actingAs($owner)->deleteJson('/v1/segments/'.$segment['id'])->assertNoContent();

        // The announcement is a thing that happened. Its deliveries are the record of what it
        // reached, and they outlive the list it was addressed to.
        app(TenantContext::class)->runAs($fixture['tenant'], function () {
            $this->assertSame(0, Segment::count());
            $this->assertSame(1, MessageDelivery::where('kind', 'announcement')->count());
        });

        $listed = $this->actingAs($owner)->getJson('/v1/messaging/announcements')->assertOk()->json('data');

        $this->assertSame('segment', $listed[0]['audience']);
        $this->assertNull($listed[0]['segment']);
    }

    /* ------------------------------------------------------------------------------ helpers */

    /**
     * These people agreed to hear about things they have not bought.
     *
     * A saved audience is marketing by definition — it describes people by what they bought
     * *before*, in order to tell them about something else — so every send here has to say who
     * agreed. Silence is not consent, and a test that did not have to say it would be testing the
     * behaviour this platform deliberately no longer has.
     */
    private function agreed(array $fixture, string ...$emails): void
    {
        app(TenantContext::class)->runAs($fixture['tenant'], function () use ($emails) {
            foreach ($emails as $email) {
                app(\App\Domain\Privacy\Consents::class)->record($email, 'in', 'checkout');
            }
        });
    }

    /** @return list<string> the addresses a rule set describes, sorted */
    private function resolve(array $fixture, array $rules): array
    {
        return app(TenantContext::class)->runAs($fixture['tenant'], function () use ($rules) {
            $segments = app(Segments::class);
            $people = $segments->resolve($rules)->pluck('email')->sort()->values()->all();

            // The count and the list are the same question asked two ways, and a screen that
            // promised 400 and sent 380 would be worse than either.
            $this->assertSame(count($people), $segments->count($rules));

            return $people;
        });
    }

    /** A second night on the same chart, so "last season" and "this one" can both exist. */
    private function anotherNight(array $fixture, string $name, ?string $category = null): Event
    {
        return app(TenantContext::class)->runAs($fixture['tenant'], function () use ($fixture, $name, $category) {
            $event = Event::create([
                'venue_id' => $fixture['venue']->id,
                'seat_map_id' => $fixture['map']->id,
                'seat_map_version_id' => $fixture['event']->seat_map_version_id,
                'public_id' => 'evt_'.Str::lower(Str::random(20)),
                'name' => $name,
                'category' => $category,
                'status' => 'published',
                'starts_at' => now()->addMonths(6),
                'timezone' => 'Europe/Berlin',
                'currency' => 'EUR',
            ]);

            EventPriceZone::create([
                'event_id' => $event->id,
                'key' => 'standard',
                'name' => 'Standard',
                'amount' => 2500,
            ]);

            return $event;
        });
    }

    /** @param  list<int>  $seats */
    private function sell(array $fixture, array $buyer, array $seats, ?Event $event = null): void
    {
        $event ??= $fixture['event'];
        $reference = 'wc_'.Str::lower(Str::random(10));

        $hold = $this->postJson("/v1/embed/events/{$event->public_id}/holds", [
            'seat_ids' => array_map(fn (int $index) => $fixture['seats'][$index]->id, $seats),
            'session_id' => 'sess_'.Str::random(8),
        ])->assertCreated()->json();

        $api = $this->clients[$fixture['tenant']->id] ??= $this->makeApiClient($fixture['tenant']);
        $body = json_encode(['external_order_id' => $reference, 'hold_token' => $hold['hold_token']]);

        $this->call(
            'POST', '/v1/integrations/woocommerce/orders', [], [], [],
            $this->serverHeaders($this->signedHeaders(
                $api['key_id'], $api['secret'], 'POST', '/v1/integrations/woocommerce/orders', $body
            )),
            $body,
        )->assertCreated();

        $path = '/v1/integrations/woocommerce/orders/'.$reference.'/confirm';
        $payload = json_encode(['buyer' => $buyer]);

        $this->call(
            'POST', $path, [], [], [],
            $this->serverHeaders($this->signedHeaders($api['key_id'], $api['secret'], 'POST', $path, $payload)),
            $payload,
        )->assertOk();
    }
}
