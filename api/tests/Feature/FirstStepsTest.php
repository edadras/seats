<?php

namespace Tests\Feature;

use App\Domain\Onboarding\FirstSteps;
use App\Domain\Sites\SiteProvisioner;
use App\Models\ApiClient;
use App\Models\Event;
use App\Models\ExternalOrder;
use App\Models\SeatMap;
use App\Models\SeatMapVersion;
use App\Models\SiteDomain;
use App\Models\Tenant;
use App\Models\TenantModule;
use App\Models\Venue;
use App\Modules\ModuleRegistry;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * The eight things between a new account and its first real sale.
 *
 * What is being held up here is not the list — a list is easy — but the two properties that decide
 * whether anybody trusts it. Every step is read from the account rather than ticked by a visit, so
 * deleting the thing un-ticks the step; and the whole checklist retires itself the moment the
 * account has taken a real booking, because a beginners' list shown to an established venue is a
 * product that does not know who it is talking to.
 *
 * The subtle case, tested twice below, is "a way to be paid". Paying at the box office is switched
 * on for every new account before the organiser has decided anything, so ticking that step off its
 * presence would be the checklist lying on day zero.
 */
class FirstStepsTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function a_brand_new_account_has_done_none_of_it(): void
    {
        $tenant = $this->makeTenant();

        $answer = app(TenantContext::class)->runAs($tenant, fn () => app(FirstSteps::class)->all());

        $this->assertSame(0, $answer['done']);
        $this->assertSame(8, $answer['total']);
        $this->assertFalse($answer['complete']);
        $this->assertFalse($answer['settled']);
        $this->assertSame('venue', $answer['next']);
        $this->assertSame(FirstSteps::STEPS, array_column($answer['steps'], 'key'));
    }

    #[Test]
    public function each_step_reads_the_account_rather_than_a_stored_tick(): void
    {
        ['tenant' => $tenant] = $this->makeSellableEvent();

        $done = $this->stepsOf($tenant);

        // One fixture settles five of the eight: a venue, a published plan, a night, prices on it,
        // and — because it is published against that plan and has not happened yet — on sale.
        $this->assertTrue($done['venue']);
        $this->assertTrue($done['plan']);
        $this->assertTrue($done['night']);
        $this->assertTrue($done['prices']);
        $this->assertTrue($done['onsale']);

        $this->assertFalse($done['payment']);
        $this->assertFalse($done['shopfront']);
        $this->assertFalse($done['rehearsal']);
    }

    #[Test]
    public function deleting_the_venue_unticks_the_step(): void
    {
        $tenant = $this->makeTenant();

        app(TenantContext::class)->runAs($tenant, function () use ($tenant) {
            Venue::factory()->create(['tenant_id' => $tenant->id]);
        });

        $this->assertTrue($this->stepsOf($tenant)['venue']);

        app(TenantContext::class)->runAs($tenant, fn () => Venue::query()->delete());

        $this->assertFalse($this->stepsOf($tenant)['venue']);
    }

    #[Test]
    public function a_plan_still_in_draft_is_not_a_published_plan(): void
    {
        $tenant = $this->makeTenant();

        app(TenantContext::class)->runAs($tenant, function () use ($tenant) {
            $venue = Venue::factory()->create(['tenant_id' => $tenant->id]);
            $map = SeatMap::create(['venue_id' => $venue->id, 'name' => 'Main hall']);

            SeatMapVersion::create([
                'seat_map_id' => $map->id,
                'version' => 1,
                'status' => 'draft',
                'geometry' => $this->geometry(2, 2),
            ]);
        });

        $this->assertFalse($this->stepsOf($tenant)['plan']);
    }

    #[Test]
    public function the_box_office_being_on_by_default_is_not_a_decision_about_being_paid(): void
    {
        $tenant = $this->makeTenant();

        // The default state of a new account: the offline module auto-enabled, nothing configured.
        $this->assertTrue(app(TenantContext::class)->runAs(
            $tenant,
            fn () => app(ModuleRegistry::class)->isEnabled('seatmap/offline-payments'),
        ));

        $this->assertFalse($this->stepsOf($tenant)['payment']);
    }

    #[Test]
    public function writing_down_how_to_pay_at_the_window_is_a_decision_about_being_paid(): void
    {
        $tenant = $this->makeTenant();

        app(TenantContext::class)->runAs($tenant, function () use ($tenant) {
            TenantModule::create([
                'tenant_id' => $tenant->id,
                'module_key' => 'seatmap/offline-payments',
                'enabled' => true,
                'settings' => ['instructions' => 'Transfer to IR12 3456, and bring the reference.'],
            ]);
        });

        ModuleRegistry::forget($tenant->id);

        $this->assertTrue($this->stepsOf($tenant)['payment']);
    }

    #[Test]
    public function switching_a_gateway_on_is_a_decision_about_being_paid(): void
    {
        $tenant = $this->makeTenant();

        app(TenantContext::class)->runAs($tenant, function () use ($tenant) {
            TenantModule::create([
                'tenant_id' => $tenant->id,
                'module_key' => 'seatmap/zarinpal',
                'enabled' => true,
                'settings' => [],
            ]);
        });

        ModuleRegistry::forget($tenant->id);

        $this->assertTrue($this->stepsOf($tenant)['payment']);
    }

    #[Test]
    public function a_live_site_is_a_shopfront(): void
    {
        $tenant = $this->makeTenant();

        $this->assertFalse($this->stepsOf($tenant)['shopfront']);

        app(TenantContext::class)->runAs($tenant, function () use ($tenant) {
            $site = app(SiteProvisioner::class)->create($tenant->name);

            SiteDomain::create([
                'site_id' => $site->id,
                'hostname' => 'northgate.test',
                'is_primary' => true,
                'verification_token' => SiteDomain::newToken(),
                'verified_at' => now(),
            ]);

            $site->update(['status' => 'live']);
        });

        $this->assertTrue($this->stepsOf($tenant)['shopfront']);
    }

    #[Test]
    public function a_plugin_that_has_never_called_is_not_a_shopfront(): void
    {
        $tenant = $this->makeTenant();

        app(TenantContext::class)->runAs($tenant, function () use ($tenant) {
            ApiClient::factory()->create([
                'tenant_id' => $tenant->id,
                'kind' => 'external',
                'status' => 'active',
                'last_seen_at' => null,
            ]);
        });

        $this->assertFalse($this->stepsOf($tenant)['shopfront']);

        app(TenantContext::class)->runAs(
            $tenant,
            fn () => ApiClient::query()->update(['last_seen_at' => now()]),
        );

        $this->assertTrue($this->stepsOf($tenant)['shopfront']);
    }

    /**
     * The one step whose evidence is deliberately not the thing itself.
     *
     * A rehearsal ends by being swept away, so by the time it has been done properly there is no
     * rehearsal left to look at. The audit log is what survives it.
     */
    #[Test]
    public function a_rehearsal_is_remembered_after_it_has_been_swept_away(): void
    {
        ['tenant' => $tenant, 'event' => $event] = $this->makeSellableEvent();

        $this->assertFalse($this->stepsOf($tenant)['rehearsal']);

        app(TenantContext::class)->runAs($tenant, function () use ($event) {
            app(AuditLogger::class)->record('rehearsal.started', $event, ['event' => $event->name]);
        });

        $this->assertTrue($this->stepsOf($tenant)['rehearsal']);
    }

    #[Test]
    public function a_night_being_rehearsed_is_not_a_night_on_sale(): void
    {
        ['tenant' => $tenant] = $this->makeSellableEvent();

        $this->assertTrue($this->stepsOf($tenant)['onsale']);

        app(TenantContext::class)->runAs(
            $tenant,
            fn () => Event::query()->update(['is_rehearsal' => true]),
        );

        $this->assertFalse($this->stepsOf($tenant)['onsale']);
    }

    #[Test]
    public function the_checklist_retires_itself_once_a_real_booking_arrives(): void
    {
        ['tenant' => $tenant, 'event' => $event] = $this->makeSellableEvent();

        $this->assertFalse($this->answer($tenant)['settled']);

        // A rehearsal's booking is not a real one, and must not retire anything.
        app(TenantContext::class)->runAs($tenant, function () use ($event) {
            // Written through the query builder: the flag is guarded against mass assignment, and
            // rightly so — turning a night into a rehearsal is {@see Rehearsals::start()}'s job.
            Event::whereKey($event->id)->update(['is_rehearsal' => true]);

            $this->confirmedOrder($event);
        });

        $this->assertFalse($this->answer($tenant)['settled']);

        app(TenantContext::class)->runAs(
            $tenant,
            fn () => Event::query()->update(['is_rehearsal' => false]),
        );

        $this->assertTrue($this->answer($tenant)['settled']);
    }

    /* ------------------------------------------------------------------------ the endpoint */

    #[Test]
    public function the_endpoint_answers_an_owner(): void
    {
        $tenant = $this->makeTenant();
        $owner = $this->makeUser($tenant, 'owner');

        $this->asMember($owner)
            ->getJson('/v1/first-steps')
            ->assertOk()
            ->assertJsonPath('total', 8)
            ->assertJsonPath('next', 'venue')
            ->assertJsonCount(8, 'steps');
    }

    #[Test]
    public function the_endpoint_refuses_somebody_who_does_not_run_the_account(): void
    {
        $tenant = $this->makeTenant();

        $this->asMember($this->makeUser($tenant, 'box_office'))
            ->getJson('/v1/first-steps')
            ->assertForbidden();
    }

    /* --------------------------------------------------------------------------- helpers */

    /** @return array<string, bool> */
    private function stepsOf(Tenant $tenant): array
    {
        return array_column($this->answer($tenant)['steps'], 'done', 'key');
    }

    private function answer(Tenant $tenant): array
    {
        // Which modules a tenant has on is cached for five minutes, which is right in production
        // and wrong in a test that switches one on and asks again in the same second.
        ModuleRegistry::forget($tenant->id);

        return app(TenantContext::class)->runAs($tenant, fn () => app(FirstSteps::class)->all());
    }

    private function confirmedOrder(Event $event): ExternalOrder
    {
        return ExternalOrder::create([
            'event_id' => $event->id,
            'api_client_id' => ApiClient::factory()->create(['tenant_id' => $event->tenant_id])->id,
            'external_order_id' => 'ord_'.uniqid(),
            'currency' => $event->currency,
            'status' => 'confirmed',
            'confirmed_at' => now(),
        ]);
    }
}
