<?php

namespace Tests\Feature;

use App\Models\TenantInvitation;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * Taking up an invitation.
 *
 * Issuing one was here from the start; taking one up was not, which meant an organiser could invite
 * a colleague and that colleague could never get in — the panel copied a link to a page that did
 * not exist. These are the tests for the other half.
 *
 * The load-bearing rule is that the token says which account and which role, and the password says
 * who is holding the link. Neither is enough on its own, and neither is inferred from the other.
 */
class TeamInvitationTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    /** @return array{tenant: \App\Models\Tenant, token: string, admin: User} */
    private function invitation(string $email = 'newcomer@northgate.test', string $role = 'box_office'): array
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser($tenant, 'admin');

        $token = $this->actingAs($admin)
            ->postJson('/v1/team/invitations', ['email' => $email, 'role' => $role])
            ->assertCreated()
            ->json('token');

        return ['tenant' => $tenant, 'token' => $token, 'admin' => $admin];
    }

    #[Test]
    public function the_link_says_who_invited_them_and_as_what_before_it_asks_for_anything(): void
    {
        $invited = $this->invitation();

        $seen = $this->postJson('/v1/team/invitations/inspect', ['token' => $invited['token']])
            ->assertOk()
            ->json();

        /*
         * A form that asks for a password before saying whose account it is asks for trust it has
         * not earned. So the screen knows all of this before anybody types anything.
         */
        $this->assertSame('newcomer@northgate.test', $seen['email']);
        $this->assertSame($invited['tenant']->name, $seen['organiser']);
        $this->assertSame('box_office', $seen['role']);
        $this->assertFalse($seen['has_account']);
    }

    #[Test]
    public function somebody_new_chooses_a_password_and_is_signed_in_where_they_were_invited(): void
    {
        $invited = $this->invitation();

        $joined = $this->postJson('/v1/team/invitations/accept', [
            'token' => $invited['token'],
            'name' => 'Rosa Iqbal',
            'password' => 'a-long-enough-one',
        ])->assertCreated()->json();

        $this->assertNotEmpty($joined['token']);
        $this->assertSame($invited['tenant']->name, $joined['tenant']['name']);
        $this->assertSame('box_office', $joined['role']);
        $this->assertContains('orders.refund', $joined['permissions']);
        $this->assertNotContains('maps.publish', $joined['permissions']);

        /*
         * Verified as it is made. The invitation went to this address and came back carrying its
         * token, which is the same proof a six-digit code gives and one round-trip fewer — and a
         * bar promising a code that was never sent is worse than no bar.
         */
        $this->assertTrue($joined['email_verified']);

        $user = User::where('email', 'newcomer@northgate.test')->firstOrFail();
        $this->assertSame('Rosa Iqbal', $user->name);

        $membership = app(TenantContext::class)->runAs(
            $invited['tenant'],
            fn () => TenantUser::where('user_id', $user->id)->first()
        );

        $this->assertNotNull($membership);
        $this->assertSame('box_office', $membership->role);

        /*
         * And the token they were handed works, at the account they were invited to.
         *
         * Forgetting the guards first because Sanctum resolves one per test and keeps the user it
         * found — without this the assertion below is answered as the admin who did the inviting,
         * and would pass while proving nothing.
         */
        app('auth')->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$joined['token'])
            ->getJson('/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('role', 'box_office');
    }

    #[Test]
    public function a_link_is_worth_one_membership_and_no_more(): void
    {
        $invited = $this->invitation();

        $this->postJson('/v1/team/invitations/accept', [
            'token' => $invited['token'],
            'name' => 'Rosa Iqbal',
            'password' => 'a-long-enough-one',
        ])->assertCreated();

        // Somebody who kept the link, or forwarded it.
        $this->postJson('/v1/team/invitations/accept', [
            'token' => $invited['token'],
            'name' => 'Somebody Else',
            'password' => 'a-long-enough-one',
        ])->assertStatus(409)->assertJsonPath('error.code', 'invitation_spent');

        $this->postJson('/v1/team/invitations/inspect', ['token' => $invited['token']])
            ->assertStatus(409);
    }

    #[Test]
    public function a_week_old_link_is_told_apart_from_one_that_was_never_real(): void
    {
        $invited = $this->invitation();

        app(TenantContext::class)->runAs(
            $invited['tenant'],
            fn () => TenantInvitation::firstOrFail()->forceFill(['expires_at' => now()->subDay()])->save()
        );

        /*
         * Whoever holds this link was sent it, so "this expired last Tuesday" is what they need to
         * hear — and it tells somebody who guessed a token nothing they did not already know.
         */
        $this->postJson('/v1/team/invitations/accept', [
            'token' => $invited['token'],
            'password' => 'a-long-enough-one',
        ])->assertStatus(422)->assertJsonPath('error.code', 'invitation_expired');

        /*
         * A token nobody ever issued. `not_found` is the code — several things say it — and the
         * sentence is the one written for this key, in the reader's own language.
         */
        $this->postJson('/v1/team/invitations/inspect', ['token' => 'nothing-like-a-real-token'])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found')
            ->assertJsonPath('error.message', __('errors.invitation_unknown'));
    }

    #[Test]
    public function an_address_that_already_has_an_account_proves_it_is_them(): void
    {
        $elsewhere = $this->makeTenant('Southgate Hall');
        $existing = $this->makeUser($elsewhere, 'door');
        $existing->forceFill(['password' => Hash::make('their-own-password')])->save();

        $invited = $this->invitation($existing->email, 'manager');

        $this->postJson('/v1/team/invitations/inspect', ['token' => $invited['token']])
            ->assertOk()
            ->assertJsonPath('has_account', true);

        // The invitation says this address may join. It does not say who is holding the link.
        $this->postJson('/v1/team/invitations/accept', [
            'token' => $invited['token'],
            'password' => 'not-their-password',
        ])->assertUnauthorized()->assertJsonPath('error.code', 'invalid_credentials');

        $joined = $this->postJson('/v1/team/invitations/accept', [
            'token' => $invited['token'],
            'password' => 'their-own-password',
        ])->assertCreated()->json();

        $this->assertSame('manager', $joined['role']);

        // Both memberships, because a person can work for two organisers.
        $this->assertSame(2, TenantUser::withoutGlobalScopes()->where('user_id', $existing->id)->count());
    }

    #[Test]
    public function a_role_deleted_since_the_invitation_went_out_is_refused_rather_than_guessed_at(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser($tenant, 'admin');

        $this->actingAs($admin)->postJson('/v1/roles', [
            'key' => 'usher',
            'name' => 'Usher',
            'permissions' => ['events.view'],
        ])->assertCreated();

        $token = $this->actingAs($admin)->postJson('/v1/team/invitations', [
            'email' => 'newcomer@northgate.test',
            'role' => 'usher',
        ])->assertCreated()->json('token');

        app(TenantContext::class)->runAs(
            $tenant,
            fn () => \App\Models\TenantRole::where('key', 'usher')->delete()
        );

        /*
         * Refused rather than substituted. Guessing at what somebody meant to grant is how a person
         * ends up holding more than was meant, and the organiser can send a new invitation in the
         * time it takes to read the refusal.
         */
        $this->postJson('/v1/team/invitations/accept', [
            'token' => $token,
            'name' => 'Rosa Iqbal',
            'password' => 'a-long-enough-one',
        ])->assertStatus(422)->assertJsonPath('error.code', 'unknown_role');

        $this->assertNull(User::where('email', 'newcomer@northgate.test')->first());
    }

    #[Test]
    public function a_short_password_is_refused_before_anything_is_created(): void
    {
        $invited = $this->invitation();

        // Twelve, the same as signing up: this password protects a box office.
        $this->postJson('/v1/team/invitations/accept', [
            'token' => $invited['token'],
            'name' => 'Rosa Iqbal',
            'password' => 'short',
        ])->assertStatus(422);

        $this->assertNull(User::where('email', 'newcomer@northgate.test')->first());

        $stillPending = app(TenantContext::class)->runAs(
            $invited['tenant'],
            fn () => TenantInvitation::firstOrFail()
        );

        $this->assertNull($stillPending->accepted_at);
    }
}
