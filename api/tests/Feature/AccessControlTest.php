<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\TenantInvitation;
use App\Models\TenantRole;
use App\Models\TenantUser;
use App\Support\Access\Permissions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * Permissions, roles and the audit log.
 *
 * `canWrite()` — one boolean for every mutation in the system — is what this replaced. Its problem
 * was not that it was coarse but that it was undiscussable: there was no way to give somebody the
 * box office without also giving them the seat maps, the website and the API keys.
 *
 * So the tests that matter here are the ones about *separation*: that a door volunteer cannot see
 * the takings, that a box office cannot republish a map, and that an account cannot lock itself out
 * of its own permissions.
 */
class AccessControlTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function the_door_can_see_who_came_in_and_not_what_the_evening_took(): void
    {
        $fixture = $this->makeSellableEvent();
        $door = $this->makeUser($fixture['tenant'], 'door');
        $event = $fixture['event'];

        // This is the separation the whole exercise is for. A volunteer on the door needs to know
        // who has arrived. They have no business knowing the revenue.
        $this->actingAs($door)->getJson("/v1/events/{$event->id}/checkins")->assertOk();

        // The head count, yes — that is what a door needs. The takings, no: the same endpoint
        // answers both questions and only answers the second to somebody who may hear it.
        $stats = $this->actingAs($door)->getJson("/v1/events/{$event->id}/stats")->assertOk();
        $this->assertArrayHasKey('checked_in', $stats->json());
        $this->assertArrayNotHasKey('gross_amount', $stats->json());
        $this->actingAs($door)->getJson('/v1/venues')->assertForbidden();
        $this->actingAs($door)->getJson('/v1/audit')->assertForbidden();
    }

    #[Test]
    public function the_box_office_can_refund_a_booking_and_not_touch_the_map_it_is_on(): void
    {
        $fixture = $this->makeSellableEvent();
        $staff = $this->makeUser($fixture['tenant'], 'box_office');
        $event = $fixture['event'];

        $this->actingAs($staff)->getJson('/v1/tickets?event_id='.$event->id)->assertOk();
        $this->actingAs($staff)->getJson("/v1/events/{$event->id}/stats")->assertOk();

        // Reading a map to find where a seat is: fine. Republishing it under a live sale: not.
        $this->actingAs($staff)->getJson('/v1/seat-maps')->assertOk();
        $this->actingAs($staff)
            ->postJson('/v1/seat-maps', ['venue_id' => $fixture['venue']->id ?? null, 'name' => 'Sneaky'])
            ->assertForbidden();
    }

    #[Test]
    public function a_viewer_reads_and_changes_nothing(): void
    {
        $fixture = $this->makeSellableEvent();
        $viewer = $this->makeUser($fixture['tenant'], 'viewer');
        $event = $fixture['event'];

        $this->actingAs($viewer)->getJson('/v1/events')->assertOk();
        $this->actingAs($viewer)->patchJson("/v1/events/{$event->id}", ['name' => 'Renamed'])->assertForbidden();
        $this->actingAs($viewer)->getJson('/v1/tickets?event_id='.$event->id)->assertForbidden();
    }

    #[Test]
    public function an_owner_holds_everything_including_permissions_added_later(): void
    {
        // Expressed as "everything" rather than as a list, because a list is what leaves an account
        // locked out of a feature the week it ships.
        $this->assertSame(Permissions::keys(), Permissions::forRole('owner'));

        foreach (Permissions::keys() as $permission) {
            $this->assertContains($permission, Permissions::forRole('owner'));
        }
    }

    #[Test]
    public function a_role_that_does_not_exist_holds_nothing(): void
    {
        // A typo in a role name must lock somebody out, never let them in.
        $this->assertSame([], Permissions::forRole('adminn'));
        $this->assertSame([], Permissions::forRole(''));

        $fixture = $this->makeSellableEvent();
        $ghost = $this->makeUser($fixture['tenant'], 'a_role_nobody_defined');

        $this->actingAs($ghost)->getJson('/v1/events')->assertForbidden();
    }

    #[Test]
    public function an_organiser_can_invent_a_role_for_a_job_their_venue_actually_has(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant'], 'owner');

        $this->actingAs($owner)->postJson('/v1/roles', [
            'key' => 'programmer',
            'name' => 'Programmer',
            // Books the shows, never sees the money.
            'permissions' => ['events.view', 'events.manage', 'venues.view', 'maps.view'],
        ])->assertCreated();

        $person = $this->makeUser($fixture['tenant'], 'programmer');

        $this->actingAs($person)->getJson('/v1/events')->assertOk();
        $this->actingAs($person)->getJson('/v1/tickets?event_id='.$fixture['event']->id)->assertForbidden();
    }

    #[Test]
    public function a_custom_role_cannot_hold_a_permission_that_does_not_exist(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant'], 'owner');

        $this->actingAs($owner)->postJson('/v1/roles', [
            'key' => 'creative',
            'name' => 'Creative',
            // The first two are real; the rest are somebody hoping.
            'permissions' => ['events.view', 'billing.manage', 'everything', '*', 'events.view; DROP TABLE seats'],
        ])->assertCreated()
          ->assertJsonPath('permissions', ['events.view', 'billing.manage']);
    }

    #[Test]
    public function a_custom_role_cannot_impersonate_a_built_in_one(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant'], 'owner');

        // A role called `owner` holding five permissions would be the worst kind of surprise.
        $this->actingAs($owner)->postJson('/v1/roles', [
            'key' => 'owner',
            'name' => 'Not really the owner',
            'permissions' => ['events.view'],
        ])->assertStatus(422)->assertJsonPath('error.code', 'reserved_role');
    }

    #[Test]
    public function nobody_changes_their_own_role(): void
    {
        $fixture = $this->makeSellableEvent();
        $admin = $this->makeUser($fixture['tenant'], 'admin');

        $membership = app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => TenantUser::where('user_id', $admin->id)->firstOrFail()
        );

        // Without this the permission system is advice: anyone with team.manage could hand
        // themselves everything, and the gap between a box office and an owner would be one
        // request wide.
        $this->actingAs($admin)
            ->patchJson("/v1/team/members/{$membership->id}", ['role' => 'owner'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'cannot_change_own_role');
    }

    #[Test]
    public function an_account_cannot_lose_its_last_owner(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant'], 'owner');
        $second = $this->makeUser($fixture['tenant'], 'owner');
        $admin = $this->makeUser($fixture['tenant'], 'admin');

        $memberships = app(TenantContext::class)->runAs($fixture['tenant'], fn () => [
            'owner' => TenantUser::where('user_id', $owner->id)->firstOrFail(),
            'second' => TenantUser::where('user_id', $second->id)->firstOrFail(),
        ]);

        // Two owners: demoting one is fine.
        $this->actingAs($admin)
            ->patchJson("/v1/team/members/{$memberships['second']->id}", ['role' => 'manager'])
            ->assertOk();

        // One left: demoting them would leave an account nobody can ever grant anything in again,
        // recoverable only through a support ticket and a database console.
        $this->actingAs($admin)
            ->patchJson("/v1/team/members/{$memberships['owner']->id}", ['role' => 'manager'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'last_owner');

        // And suspending them is the same act by another name.
        $this->actingAs($admin)
            ->patchJson("/v1/team/members/{$memberships['owner']->id}", ['suspended' => true])
            ->assertStatus(409);
    }

    #[Test]
    public function a_suspended_member_keeps_their_history_and_loses_their_access(): void
    {
        $fixture = $this->makeSellableEvent();
        $admin = $this->makeUser($fixture['tenant'], 'admin');
        $manager = $this->makeUser($fixture['tenant'], 'manager');

        $membership = app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => TenantUser::where('user_id', $manager->id)->firstOrFail()
        );

        $this->actingAs($manager)->getJson('/v1/events')->assertOk();

        $this->actingAs($admin)
            ->patchJson("/v1/team/members/{$membership->id}", ['suspended' => true])
            ->assertOk()
            ->assertJsonPath('suspended', true);

        $this->actingAs($manager)
            ->getJson('/v1/events')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'member_suspended');

        // The membership still exists, so every audit row naming them still resolves to a person.
        $this->assertNotNull($membership->fresh());
    }

    #[Test]
    public function an_invitation_token_exists_once_and_is_stored_hashed(): void
    {
        $fixture = $this->makeSellableEvent();
        $admin = $this->makeUser($fixture['tenant'], 'admin');

        $response = $this->actingAs($admin)->postJson('/v1/team/invitations', [
            'email' => 'newcomer@northgate.test',
            'role' => 'box_office',
        ])->assertCreated();

        $token = $response->json('token');
        $this->assertNotEmpty($token);

        $invitation = app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => TenantInvitation::firstOrFail()
        );

        // An invitation link is a way into an account; a leaked database should not be a set of
        // working ones.
        $this->assertNotSame($token, $invitation->token_hash);
        $this->assertSame(TenantInvitation::hashToken($token), $invitation->token_hash);

        // And listing invitations never hands it back.
        $listed = $this->actingAs($admin)->getJson('/v1/team')->assertOk();
        $this->assertStringNotContainsString($token, $listed->getContent());
    }

    #[Test]
    public function the_first_screen_shows_the_door_what_it_may_see_and_nothing_else(): void
    {
        $fixture = $this->makeSellableEvent();
        $door = $this->makeUser($fixture['tenant'], 'door');
        $owner = $this->makeUser($fixture['tenant'], 'owner');

        $seen = $this->actingAs($door)->getJson('/v1/overview')->assertOk();

        // The overview is the one screen where numbers from four permissions meet, so it is the
        // one most likely to hand a volunteer the takings by accident.
        $this->assertNull($seen->json('money'));
        $this->assertNull($seen->json('activity'));
        $this->assertNotNull($seen->json('door'));
        $this->assertNull($seen->json('next.0.gross_amount'));
        // What they are there for is still there.
        $this->assertIsInt($seen->json('next.0.allocated'));

        $all = $this->actingAs($owner)->getJson('/v1/overview')->assertOk();

        $this->assertNotNull($all->json('money'));
        $this->assertNotNull($all->json('activity'));
        $this->assertIsInt($all->json('next.0.gross_amount'));
    }

    #[Test]
    public function the_audit_log_records_what_changed_and_not_only_that_something_did(): void
    {
        $fixture = $this->makeSellableEvent();
        $admin = $this->makeUser($fixture['tenant'], 'admin');
        $event = $fixture['event'];

        $this->actingAs($admin)
            ->patchJson("/v1/events/{$event->id}", ['name' => 'Opening night, renamed'])
            ->assertOk();

        $entry = $this->actingAs($admin)->getJson('/v1/audit')->assertOk()->json('data.0');

        $this->assertSame('event.updated', $entry['action']);
        $this->assertSame($admin->name, $entry['actor']['name']);
        // "Someone updated an event" answers nothing at four in the morning.
        $this->assertSame('Opening night, renamed', $entry['changes']['name']['to']);
        $this->assertNotSame($entry['changes']['name']['to'], $entry['changes']['name']['from']);
        // And a label, so a page of UUIDs is a page somebody can read.
        $this->assertSame('Opening night, renamed', $entry['subject']['label']);
    }

    #[Test]
    public function the_audit_log_reads_when_a_shop_wrote_a_row_and_not_only_when_a_person_did(): void
    {
        $fixture = $this->makeSellableEvent();
        $admin = $this->makeUser($fixture['tenant'], 'admin');

        // What a signed request from a WooCommerce store leaves behind: an actor that is a key,
        // not a person. `actor_id` is then an `ak_…` id rather than a UUID, and the screen used to
        // hand that straight to `users.id` — a uuid column, so Postgres answered with a 500 rather
        // than an empty result. It took a real shop selling a real seat to put such a row in the
        // table, which is why every test passed and the screen still broke.
        AuditLog::create([
            'tenant_id' => $fixture['tenant']->id,
            'actor_type' => 'api_key',
            'actor_id' => 'ak_xa3smlenyhtg9fewzz5jwxa8',
            'action' => 'order.confirmed',
            'subject_type' => 'Order',
            'subject_id' => (string) \Illuminate\Support\Str::uuid(),
            'subject_label' => 'WC-1043',
            'created_at' => now(),
        ]);

        $entry = $this->actingAs($admin)->getJson('/v1/audit')->assertOk()->json('data.0');

        $this->assertSame('order.confirmed', $entry['action']);
        $this->assertSame('api_key', $entry['actor']['type']);
        $this->assertSame('ak_xa3smlenyhtg9fewzz5jwxa8', $entry['actor']['id']);
        // A key has no name in `users`; the type is what says what it was.
        $this->assertNull($entry['actor']['name']);
    }

    #[Test]
    public function the_audit_log_never_carries_a_secret(): void
    {
        $fixture = $this->makeSellableEvent();
        $admin = $this->makeUser($fixture['tenant'], 'admin');

        $this->actingAs($admin)->patchJson('/v1/modules/seatmap.offline-payments', [
            'settings' => ['instructions' => 'Cash only'],
        ])->assertOk();

        $content = $this->actingAs($admin)->getJson('/v1/audit')->assertOk()->getContent();

        foreach (['secret', 'password', 'api_key', 'token_hash'] as $forbidden) {
            $this->assertStringNotContainsString('"'.$forbidden.'":"', $content);
        }
    }

    #[Test]
    public function one_organiser_never_sees_anothers_staff(): void
    {
        $first = $this->makeSellableEvent();
        $second = $this->makeSellableEvent();

        $this->makeUser($first['tenant'], 'admin');
        $stranger = $this->makeUser($second['tenant'], 'manager');
        $admin = $this->makeUser($first['tenant'], 'owner');

        $team = $this->actingAs($admin)->getJson('/v1/team')->assertOk();

        // Memberships went unscoped for a long time because the only code reading them was the
        // middleware that resolves a tenant *from* them. The moment a screen listed them, that
        // became a page showing one organiser another's staff, by name and email address.
        $this->assertStringNotContainsString($stranger->email, $team->getContent());

        foreach ($team->json('data') as $member) {
            $this->assertNotSame($stranger->id, $member['user_id']);
        }
    }

    #[Test]
    public function one_organisers_audit_log_is_not_anothers(): void
    {
        $first = $this->makeSellableEvent();
        $second = $this->makeSellableEvent();

        $firstAdmin = $this->makeUser($first['tenant'], 'admin');
        $secondAdmin = $this->makeUser($second['tenant'], 'admin');

        $this->actingAs($firstAdmin)
            ->patchJson("/v1/events/{$first['event']->id}", ['name' => 'A secret rename'])
            ->assertOk();

        $content = $this->actingAs($secondAdmin)->getJson('/v1/audit')->assertOk()->getContent();

        $this->assertStringNotContainsString('A secret rename', $content);
    }

    #[Test]
    public function an_unbound_audit_read_fails_closed_rather_than_showing_everything(): void
    {
        // The log is deliberately not tenant-scoped at the model level, because system actions
        // with no tenant must still be recorded. That makes reading the dangerous direction.
        $this->expectException(\RuntimeException::class);

        AuditLog::visible();
    }

    #[Test]
    public function a_role_still_held_by_somebody_cannot_be_deleted(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant'], 'owner');

        $this->actingAs($owner)->postJson('/v1/roles', [
            'key' => 'usher', 'name' => 'Usher', 'permissions' => ['events.view'],
        ])->assertCreated();

        $this->makeUser($fixture['tenant'], 'usher');

        $role = app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => TenantRole::where('key', 'usher')->firstOrFail()
        );

        // Deleting it would leave that person with nothing, silently.
        $this->actingAs($owner)
            ->deleteJson("/v1/roles/{$role->id}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'role_in_use');
    }
}
