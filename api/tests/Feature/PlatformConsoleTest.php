<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * The platform's own console.
 *
 * The console reads every organiser's data, so the tests that matter are the ones about who cannot
 * open it: an organiser's owner, an organiser's admin, anybody with a tenant role at all. A role
 * inside an account must never be a way into the console, because then every account's data would
 * be one bug away from every other account's.
 */
class PlatformConsoleTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function an_operator_sees_every_organiser(): void
    {
        $first = $this->makeSellableEvent();
        $second = $this->makeSellableEvent($this->makeTenant('Riverside'));
        $operator = $this->operator();

        $body = $this->actingAs($operator)->getJson('/v1/admin/tenants')->assertOk()->json();

        $names = array_column($body['data'], 'name');

        $this->assertContains($first['tenant']->name, $names);
        $this->assertContains('Riverside', $names);
    }

    #[Test]
    public function a_tenants_detail_keeps_counts_and_lists_apart(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeUser($fixture['tenant']);
        $operator = $this->operator();

        $body = $this->actingAs($operator)
            ->getJson('/v1/admin/tenants/'.$fixture['tenant']->id)
            ->assertOk()
            ->json();

        // `people` is a number in the list and must stay one here; the list of them is `members`.
        // A field that is a count in one response and an array in another is a field somebody
        // reads as the wrong one — and did.
        $this->assertIsInt($body['people']);
        $this->assertIsArray($body['members']);
        $this->assertIsInt($body['sites']);
        $this->assertIsArray($body['websites']);
    }

    #[Test]
    public function an_organisers_owner_cannot_open_the_console_at_all(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        // Not "forbidden" — not found. Who runs the platform is not discoverable by trying.
        foreach ([
            '/v1/admin/overview',
            '/v1/admin/tenants',
            '/v1/admin/plans',
            '/v1/admin/audit',
        ] as $path) {
            $this->actingAs($owner)->getJson($path)->assertNotFound();
        }

        $this->actingAs($owner)
            ->patchJson('/v1/admin/tenants/'.$fixture['tenant']->id, ['status' => 'suspended'])
            ->assertNotFound();
    }

    #[Test]
    public function the_consoles_login_refuses_an_organiser_and_accepts_an_operator(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $owner->forceFill(['password' => Hash::make('correct horse battery')])->save();

        $this->postJson('/v1/admin/login', [
            'email' => $owner->email,
            'password' => 'correct horse battery',
        ])->assertStatus(401);

        $operator = $this->operator();

        $body = $this->postJson('/v1/admin/login', [
            'email' => $operator->email,
            'password' => 'correct horse battery',
        ])->assertOk()->json();

        $this->assertSame('operator', $body['level']);
        $this->assertNotEmpty($body['token']);

        // Arriving is itself recorded: somebody who can read everything leaves a trail from the
        // door, not from the first thing they change.
        $this->assertSame(1, PlatformAuditLog::where('action', 'console.signed_in')->count());
    }

    #[Test]
    public function support_can_look_and_cannot_change(): void
    {
        $fixture = $this->makeSellableEvent();
        $support = $this->operator('support@platform.test', 'support');

        $this->actingAs($support)->getJson('/v1/admin/tenants')->assertOk();

        $this->actingAs($support)
            ->patchJson('/v1/admin/tenants/'.$fixture['tenant']->id, ['status' => 'suspended'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'support_may_not_change');

        $this->assertSame('active', $fixture['tenant']->fresh()->status);
    }

    #[Test]
    public function suspending_an_organiser_actually_stops_them(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $operator = $this->operator();

        $this->actingAs($owner)->getJson('/v1/events')->assertOk();

        $this->actingAs($operator)->patchJson('/v1/admin/tenants/'.$fixture['tenant']->id, [
            'status' => 'suspended',
            'reason' => 'unpaid invoices',
        ])->assertOk()->assertJsonPath('status', 'suspended');

        // Not a flag on a screen: the panel refuses them.
        $this->actingAs($owner)->getJson('/v1/events')->assertForbidden();

        // And why it happened is recorded where the platform can find it later.
        $entry = PlatformAuditLog::where('action', 'tenant.updated')->firstOrFail();
        $this->assertSame('unpaid invoices', $entry->context['reason']);
        $this->assertSame($operator->id, $entry->user_id);
    }

    #[Test]
    public function moving_an_organiser_to_another_plan_changes_what_they_may_do(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $operator = $this->operator();

        $tiny = Plan::factory()->create(['key' => 'tiny', 'limits' => ['max_venues' => 1], 'is_active' => true]);

        $this->actingAs($operator)->patchJson('/v1/admin/tenants/'.$fixture['tenant']->id, [
            'plan' => 'tiny',
        ])->assertOk()->assertJsonPath('plan', 'tiny');

        // The fixture already has one venue, so the limit bites immediately — which is the point:
        // a plan limit that is not enforced is a price list nobody believes.
        $this->actingAs($owner)->postJson('/v1/venues', ['name' => 'Second hall'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'venue_limit_reached');

        $this->assertSame($tiny->id, Subscription::withoutGlobalScope('tenant')
            ->where('tenant_id', $fixture['tenant']->id)->latest('created_at')->value('plan_id'));
    }

    #[Test]
    public function a_plan_nobody_is_on_can_be_deleted_and_one_in_use_cannot(): void
    {
        $fixture = $this->makeSellableEvent();
        $operator = $this->operator();

        $unused = Plan::factory()->create(['key' => 'unused']);
        $inUse = app(TenantContext::class)->runUnscoped(
            fn () => Subscription::withoutGlobalScope('tenant')
                ->where('tenant_id', $fixture['tenant']->id)->value('plan_id')
        );

        $this->actingAs($operator)->deleteJson('/v1/admin/plans/'.$unused->id)->assertOk();

        $this->actingAs($operator)->deleteJson('/v1/admin/plans/'.$inUse)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'plan_in_use');
    }

    #[Test]
    public function a_plan_can_only_promise_what_the_platform_enforces(): void
    {
        $operator = $this->operator();

        $body = $this->actingAs($operator)->postJson('/v1/admin/plans', [
            'key' => 'invented',
            'name' => 'Invented',
            'price_amount' => 1000,
            'currency' => 'EUR',
            'interval' => 'month',
            'limits' => [
                'max_events' => 5,
                // Nothing checks this one, so it must not survive into a price list.
                'free_unicorns' => 99,
            ],
        ])->assertCreated()->json();

        $this->assertSame(['max_events' => 5], $body['limits']);
    }

    #[Test]
    public function impersonation_expires_by_itself_and_is_written_down_twice(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeUser($fixture['tenant']);
        $operator = $this->operator();

        $body = $this->actingAs($operator)
            ->postJson('/v1/admin/tenants/'.$fixture['tenant']->id.'/impersonate')
            ->assertOk()
            ->json();

        $this->assertNotEmpty($body['token']);

        $expiry = \Illuminate\Support\Carbon::parse($body['expires_at']);
        $this->assertTrue($expiry->isAfter(now()) && $expiry->isBefore(now()->addHours(2)));

        // In the platform's log…
        $this->assertSame(1, PlatformAuditLog::where('action', 'tenant.impersonated')->count());

        // …and in the organiser's own, because it is their account that was entered.
        app(TenantContext::class)->runAs($fixture['tenant'], function () {
            $this->assertSame(
                1,
                \App\Models\AuditLog::where('action', 'platform.impersonation_started')->count()
            );
        });

        // And the token works, as the owner, on their own account.
        // `actingAs` leaves the operator signed in for the rest of the test, and an already
        // authenticated guard wins over a bearer token — so put the guards back before using it.
        $this->app['auth']->forgetGuards();

        $this->withToken($body['token'])->getJson('/v1/events')->assertOk();
    }

    #[Test]
    public function support_may_not_impersonate(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeUser($fixture['tenant']);
        $support = $this->operator('support2@platform.test', 'support');

        $this->actingAs($support)
            ->postJson('/v1/admin/tenants/'.$fixture['tenant']->id.'/impersonate')
            ->assertForbidden();
    }

    #[Test]
    public function the_overview_keeps_currencies_apart(): void
    {
        $this->makeSellableEvent();
        $operator = $this->operator();

        $body = $this->actingAs($operator)->getJson('/v1/admin/overview')->assertOk()->json();

        $this->assertArrayHasKey('takings_this_month', $body);
        $this->assertIsArray($body['takings_this_month'], 'A platform has no single total.');
        $this->assertSame('operator', $body['me']['level']);
    }

    private function operator(string $email = 'operator@platform.test', string $level = 'operator'): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'password' => Hash::make('correct horse battery'),
        ]);

        PlatformAdmin::create(['user_id' => $user->id, 'level' => $level]);

        return $user;
    }
}
