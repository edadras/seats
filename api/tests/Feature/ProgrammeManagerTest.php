<?php

namespace Tests\Feature;

use App\Domain\Programme\EventManagers;
use App\Models\Event;
use App\Models\EventManager;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\Access\Permissions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * An administrator for one concert rather than for the account.
 *
 * Every other role here answers one question — what may this person do. A programme manager needs
 * the other half of the sentence, "to which nights", and the two are multiplied rather than added:
 * holding `tickets.release` and being given the Tuesday means you may void a Tuesday ticket, and
 * says nothing whatever about Wednesday.
 *
 * So the tests that matter are about the multiplication. That a manager has real authority over
 * their own night — sells at the window, opens and closes chairs on the plan, reads the door and
 * the takings — and that the same request against somebody else's night cannot be found.
 */
class ProgrammeManagerTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    /** @return array{tenant: \App\Models\Tenant, mine: Event, theirs: Event, manager: User, owner: User} */
    private function programme(): array
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant'], 'owner');

        // A second night in the same account, which this manager is not given.
        $other = app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => $this->makeSellableEvent(tenant: $fixture['tenant'])
        );

        $manager = $this->makeUser($fixture['tenant'], EventManagers::ROLE);

        app(TenantContext::class)->runAs($fixture['tenant'], fn () => EventManager::create([
            'tenant_id' => $fixture['tenant']->id,
            'user_id' => $manager->id,
            'event_id' => $fixture['event']->id,
        ]));

        return [
            'tenant' => $fixture['tenant'],
            'fixture' => $fixture,
            'mine' => $fixture['event'],
            'theirs' => $other['event'],
            'seats' => ['mine' => $fixture['seats'], 'theirs' => $other['seats']],
            'manager' => $manager,
            'owner' => $owner,
        ];
    }

    #[Test]
    public function a_manager_runs_their_own_night_the_way_the_organiser_would(): void
    {
        $programme = $this->programme();
        $mine = $programme['mine'];

        /*
         * Everything a site administrator has, for this one concert: the stats, the door, the plan,
         * the window. This is the half of the claim that is easy to lose — a role scoped so
         * carefully that it can no longer do the job it was made for.
         */
        $this->asMember($programme['manager'])->getJson("/v1/events/{$mine->id}")->assertOk();
        $this->asMember($programme['manager'])->getJson("/v1/events/{$mine->id}/stats")->assertOk();
        $this->asMember($programme['manager'])->getJson("/v1/events/{$mine->id}/checkins")->assertOk();
        $this->asMember($programme['manager'])->getJson("/v1/events/{$mine->id}/door-list")->assertOk();
        $this->asMember($programme['manager'])->getJson("/v1/events/{$mine->id}/hall")->assertOk();
        $this->asMember($programme['manager'])->getJson("/v1/events/{$mine->id}/seat-prices")->assertOk();
        $this->asMember($programme['manager'])->getJson("/v1/events/{$mine->id}/settlement")->assertOk();

        // Including the takings, which is the whole reason somebody promotes a concert.
        $money = $this->asMember($programme['manager'])
            ->getJson("/v1/events/{$mine->id}/stats")
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('gross_amount', $money);
    }

    #[Test]
    public function a_night_they_were_not_given_cannot_be_found(): void
    {
        $programme = $this->programme();
        $theirs = $programme['theirs'];

        /*
         * Not "forbidden": which nights an organiser is running is not a manager's business either,
         * and a refusal that distinguishes "no" from "no such thing" is a way of asking.
         *
         * Every one of these is a different controller, and not one of them knows what a programme
         * manager is: the scope is middleware on any route with a bound event, which is what makes
         * it hold for the routes nobody has written yet.
         */
        foreach ([
            "/v1/events/{$theirs->id}",
            "/v1/events/{$theirs->id}/stats",
            "/v1/events/{$theirs->id}/checkins",
            "/v1/events/{$theirs->id}/door-list",
            "/v1/events/{$theirs->id}/hall",
            "/v1/events/{$theirs->id}/seat-prices",
            "/v1/events/{$theirs->id}/settlement",
            "/v1/events/{$theirs->id}/counter",
        ] as $path) {
            $this->asMember($programme['manager'])
                ->getJson($path)
                ->assertNotFound();
        }

        // And writing to it is refused by the same rule, not by a second one somebody has to
        // remember to add.
        $this->asMember($programme['manager'])
            ->patchJson("/v1/events/{$theirs->id}", ['name' => 'Mine now'])
            ->assertNotFound();
    }

    #[Test]
    public function the_programme_they_are_shown_is_the_programme_they_run(): void
    {
        $programme = $this->programme();

        $listed = $this->asMember($programme['manager'])
            ->getJson('/v1/events')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $listed);
        $this->assertSame($programme['mine']->id, $listed[0]['id']);
    }

    #[Test]
    public function a_manager_with_no_nights_reaches_nothing_rather_than_everything(): void
    {
        $fixture = $this->makeSellableEvent();
        $newcomer = $this->makeUser($fixture['tenant'], EventManagers::ROLE);

        /*
         * The safe end to fail at, and the one worth a test of its own: an empty grant list that
         * read as "no restriction" would hand a promoter appointed this morning the whole season.
         */
        $this->asMember($newcomer)->getJson('/v1/events')->assertOk()->assertJsonCount(0, 'data');
        $this->asMember($newcomer)->getJson("/v1/events/{$fixture['event']->id}")->assertNotFound();
    }

    #[Test]
    public function the_accounts_own_screens_are_not_a_managers(): void
    {
        $programme = $this->programme();

        /*
         * There is no honest way to show a promoter a quarter of the customer directory, and showing
         * them all of it would hand over the organiser's audience along with the four nights they
         * were booked for. Refused with a reason rather than narrowed to nothing, because an empty
         * screen is a bug report and this is a decision.
         */
        $this->asMember($programme['manager'])->getJson('/v1/customers')->assertForbidden();
        $this->asMember($programme['manager'])->getJson('/v1/settlement')->assertForbidden();
        $this->asMember($programme['manager'])->getJson('/v1/report-pages')->assertForbidden();

        // Nor the things that are nobody's but the account's.
        $this->asMember($programme['manager'])->getJson('/v1/team')->assertForbidden();
        $this->asMember($programme['manager'])->getJson('/v1/vouchers')->assertForbidden();
        $this->asMember($programme['manager'])->getJson('/v1/sites')->assertForbidden();
    }

    #[Test]
    public function bookings_and_tickets_are_narrowed_to_the_nights_they_run(): void
    {
        $programme = $this->programme();
        $mine = $programme['mine'];
        $theirs = $programme['theirs'];
        $owner = $programme['owner'];

        // One sale on each night, both made by the organiser.
        foreach ([['mine', $mine], ['theirs', $theirs]] as [$which, $event]) {
            $this->actingAs($owner)->postJson("/v1/events/{$event->id}/sell", [
                'seat_ids' => [$programme['seats'][$which][0]->id],
                'buyer' => ['name' => 'A buyer'],
                'payment' => 'comp',
            ])->assertCreated();
        }

        $seen = $this->asMember($programme['manager'])->getJson('/v1/orders')->assertOk()->json('data');

        $this->assertCount(1, $seen);
        $this->assertSame($mine->id, $seen[0]['event']['id'] ?? null);

        $tickets = $this->asMember($programme['manager'])
            ->getJson('/v1/tickets?event_id='.$theirs->id)
            ->assertOk()
            ->json('data');

        // Asked for by query parameter, which the route scope never sees — so the narrowing is on
        // the query too, and the answer to "show me that night's tickets" is nothing.
        $this->assertCount(0, $tickets);
    }

    #[Test]
    public function an_organiser_appoints_one_and_hands_over_nights(): void
    {
        $programme = $this->programme();
        $owner = $programme['owner'];

        $made = $this->actingAs($owner)->postJson('/v1/programme-managers', [
            'name' => 'Rosa Iqbal',
            'email' => 'rosa@promoter.test',
            'event_ids' => [$programme['mine']->id],
        ])->assertCreated()->json();

        // The password exists in plaintext exactly once, here.
        $this->assertNotEmpty($made['password']);
        $this->assertSame('rosa@promoter.test', $made['email']);

        $appointed = User::where('email', 'rosa@promoter.test')->firstOrFail();

        $membership = app(TenantContext::class)->runAs(
            $programme['tenant'],
            fn () => TenantUser::where('user_id', $appointed->id)->first()
        );

        $this->assertSame(EventManagers::ROLE, $membership->role);

        app('auth')->forgetGuards();

        $this->asMember($appointed)->getJson("/v1/events/{$programme['mine']->id}")->assertOk();
        $this->asMember($appointed)->getJson("/v1/events/{$programme['theirs']->id}")->assertNotFound();

        // And the list replaces rather than adds: unticking a night takes it away.
        $this->actingAs($owner)->putJson('/v1/programme-managers/'.$appointed->id.'/events', [
            'event_ids' => [$programme['theirs']->id],
        ])->assertOk();

        app('auth')->forgetGuards();

        $this->asMember($appointed)->getJson("/v1/events/{$programme['mine']->id}")->assertNotFound();
        $this->asMember($appointed)->getJson("/v1/events/{$programme['theirs']->id}")->assertOk();
    }

    #[Test]
    public function appointing_one_takes_both_authorities(): void
    {
        $programme = $this->programme();

        // `team.manage` makes a member; `events.manage` hands over nights. A box office has
        // neither, and a manager who may add people but not run the programme has no business
        // deciding who runs the Tuesday.
        $boxOffice = $this->makeUser($programme['tenant'], 'box_office');

        $this->asMember($boxOffice)->postJson('/v1/programme-managers', [
            'name' => 'Not mine to appoint',
            'email' => 'nope@promoter.test',
        ])->assertForbidden();

        $this->assertNull(User::where('email', 'nope@promoter.test')->first());
    }

    #[Test]
    public function the_role_holds_what_a_night_needs_and_nothing_of_the_account(): void
    {
        $held = Permissions::forRole(EventManagers::ROLE);

        foreach (['events.manage', 'pricing.manage', 'tickets.release', 'orders.sell',
            'orders.refund', 'checkins.view', 'reports.orders.view'] as $needed) {
            $this->assertContains($needed, $held, "a programme manager needs {$needed}");
        }

        foreach (['team.manage', 'billing.manage', 'account.manage', 'maps.publish',
            'vouchers.manage', 'sites.manage', 'agents.manage', 'discounts.manage',
            'reports.build'] as $forbidden) {
            $this->assertNotContains($forbidden, $held, "a programme manager must not hold {$forbidden}");
        }
    }
}
