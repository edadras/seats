<?php

namespace Tests\Feature;

use App\Domain\Accounts\AccountClosure;
use App\Domain\Accounts\AccountExporter;
use App\Domain\Sites\SiteProvisioner;
use App\Models\AccountExport;
use App\Models\ApiClient;
use App\Models\Event;
use App\Models\PersonalAccessToken;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;
use ZipArchive;

/**
 * Taking an account's data, and closing the account.
 *
 * A platform nobody can leave is a platform nobody should arrive at. Three claims are worth holding
 * down, and each of them fails quietly if it fails:
 *
 *   - the archive is everything, including whatever was built last month, and nothing in it is a
 *     credential;
 *   - the link keeps working after the door is shut, because that is exactly when it is needed;
 *   - nobody can vanish while strangers are holding tickets for nights that have not happened.
 *
 * The last one is the reason closing is a refusal rather than a switch.
 */
class LeavingTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    /** @var list<string> */
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            if (is_dir($directory)) {
                File::deleteDirectory($directory);
            }
        }

        parent::tearDown();
    }

    /* --------------------------------------------------------------- taking the data */

    #[Test]
    public function an_owner_can_take_a_copy_of_everything(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $this->keep($fixture['tenant']);

        $body = $this->actingAs($owner)->postJson('/v1/account/exports')
            ->assertStatus(201)
            ->json();

        $this->assertSame('ready', $body['status']);
        $this->assertGreaterThan(0, $body['bytes']);
        $this->assertNotNull($body['link']);

        // Inclusion by default: the archive is asked of the database rather than of a list somebody
        // has to remember to extend, so a table added for a feature built last month is in it.
        $this->assertArrayHasKey('events', $body['contents']);
        $this->assertArrayHasKey('external_orders', $body['contents']);
        $this->assertArrayHasKey('account', $body['contents']);
        $this->assertGreaterThan(50, count($body['contents']));
    }

    #[Test]
    public function the_archive_holds_the_rows_and_the_seating_plans(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $this->keep($fixture['tenant']);

        $this->actingAs($owner)->postJson('/v1/account/exports')->assertStatus(201);

        $zip = new ZipArchive;
        $this->assertTrue(true === $zip->open($this->archive($fixture['tenant'])));

        $names = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $names[] = $zip->getNameIndex($index);
        }

        $this->assertContains('data/events.csv', $names);
        $this->assertContains('README.txt', $names);
        $this->assertContains('manifest.json', $names);

        // The one part of the archive that is portable in the sense that matters: a hall nobody can
        // redraw is a hall somebody has to survey again.
        $charts = array_values(array_filter($names, fn ($name) => str_starts_with($name, 'seat-maps/')));
        $this->assertCount(1, $charts);

        $chart = json_decode((string) $zip->getFromName($charts[0]), true);
        // The floors, with the rows and the chairs inside them: the plan as it is drawn rather
        // than as a list of seat ids nobody can put back on a stage.
        $this->assertNotEmpty($chart['geometry']['floors'] ?? []);

        $events = (string) $zip->getFromName('data/events.csv');
        $this->assertStringContainsString('Opening night', $events);

        $zip->close();
    }

    #[Test]
    public function a_credential_is_replaced_rather_than_handed_over(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $this->keep($fixture['tenant']);

        $this->inTenant($fixture, fn () => \Illuminate\Support\Facades\DB::table('tenant_modules')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $fixture['tenant']->id,
            'module_key' => 'seatmap/stripe',
            'enabled' => true,
            'settings' => json_encode(['secret_key' => 'sk_live_donotexport']),
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->actingAs($owner)->postJson('/v1/account/exports')->assertStatus(201);

        $zip = new ZipArchive;
        $zip->open($this->archive($fixture['tenant']));
        $modules = (string) $zip->getFromName('data/tenant_modules.csv');
        $zip->close();

        // The row still says what they had set up. The key is not theirs to carry to another
        // platform and not ours to put in a file that will sit in an inbox.
        $this->assertStringContainsString('seatmap/stripe', $modules);
        $this->assertStringNotContainsString('sk_live_donotexport', $modules);
        $this->assertStringContainsString('not exported', $modules);
    }

    #[Test]
    public function the_link_needs_no_password_and_a_tampered_one_is_nothing(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $this->keep($fixture['tenant']);

        $link = $this->actingAs($owner)->postJson('/v1/account/exports')->json('link');

        app('auth')->forgetGuards();

        $this->get($link)->assertOk()->assertHeader('content-type', 'application/zip');

        // A wrong signature is a 404 rather than a refusal: "wrong signature" would confirm that
        // the archive exists, and what is in it is every buyer this venue has ever had.
        $this->get($link.'x')->assertNotFound();
        $this->get(strtok($link, '?'))->assertNotFound();
    }

    #[Test]
    public function only_somebody_who_may_manage_the_account_can_take_it(): void
    {
        $fixture = $this->makeSellableEvent();
        $manager = $this->makeUser($fixture['tenant'], 'box_office');

        $this->actingAs($manager)->postJson('/v1/account/exports')->assertForbidden();
        $this->actingAs($manager)->getJson('/v1/account/exports')->assertForbidden();
        $this->actingAs($manager)->getJson('/v1/account/closure')->assertForbidden();
        $this->actingAs($manager)->postJson('/v1/account/closure', ['confirm' => 'x'])->assertForbidden();
    }

    /* ------------------------------------------------------------------ closing the door */

    #[Test]
    public function nobody_can_leave_while_people_are_holding_tickets(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $this->makeSite($fixture['tenant']);
        $this->buy($fixture, [0]);

        $standing = $this->actingAs($owner)->getJson('/v1/account/closure')->assertOk()->json();

        $this->assertFalse($standing['can_close']);
        $this->assertSame('Opening night', $standing['nights'][0]['name']);
        $this->assertSame(1, $standing['nights'][0]['tickets']);

        $this->actingAs($owner)->postJson('/v1/account/closure', [
            'confirm' => $fixture['tenant']->name,
        ])->assertStatus(409)->assertJsonPath('error.code', 'closure_has_live_tickets');

        $this->assertSame('active', Tenant::findOrFail($fixture['tenant']->id)->status);
    }

    #[Test]
    public function a_night_whose_tickets_have_all_gone_back_is_not_in_the_way(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $this->makeSite($fixture['tenant']);
        $this->buy($fixture, [0]);

        $order = $this->inTenant($fixture, fn () => \App\Models\ExternalOrder::firstOrFail());

        $this->actingAs($owner)->postJson('/v1/orders/'.$order->id.'/refund', [])->assertOk();

        // Refunded is not "a ticket somebody can turn up holding", which is the only question the
        // refusal is asking.
        $this->assertTrue($this->actingAs($owner)->getJson('/v1/account/closure')->json('can_close'));
    }

    #[Test]
    public function closing_needs_the_account_name_typed(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        $this->actingAs($owner)->postJson('/v1/account/closure', ['confirm' => 'not the name'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'closure_not_confirmed');

        $this->assertSame('active', Tenant::findOrFail($fixture['tenant']->id)->status);
    }

    #[Test]
    public function closing_stops_everything_at_once(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $site = $this->makeSite($fixture['tenant']);
        $token = $owner->createToken('panel')->plainTextToken;

        $this->inTenant($fixture, fn () => ApiClient::factory()->create([
            'tenant_id' => $fixture['tenant']->id,
        ]));

        $this->actingAs($owner)->postJson('/v1/account/closure', [
            'confirm' => $fixture['tenant']->name,
            'reason' => 'The theatre is closing.',
        ])->assertOk()->assertJsonPath('status', 'cancelled');

        $tenant = Tenant::findOrFail($fixture['tenant']->id);

        $this->assertSame('cancelled', $tenant->status);
        $this->assertNotNull($tenant->closed_at);
        $this->assertTrue($tenant->erase_after->isFuture());

        $this->inTenant($fixture, function () {
            // A shop with nobody behind it is worse than no shop.
            $this->assertSame(0, Site::where('status', 'live')->count());
            $this->assertSame(0, ApiClient::where('status', 'active')->count());
            $this->assertSame('cancelled', Subscription::firstOrFail()->status);
        });

        // Every session, everywhere: a colleague's laptop in a foyer is still signed in to an
        // account that is supposed to be shut.
        app('auth')->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/v1/events')->assertUnauthorized();
    }

    #[Test]
    public function a_colleague_who_works_for_another_venue_keeps_that_session(): void
    {
        $leaving = $this->makeSellableEvent();
        $staying = $this->makeSellableEvent();

        $owner = $this->makeUser($leaving['tenant']);

        // The same person, on somebody else's payroll as well. A freelance box-office manager
        // works for three venues, and one of them closing is not the other two's business.
        $both = $this->makeUser($leaving['tenant'], 'box_office');
        TenantUser::create([
            'tenant_id' => $staying['tenant']->id,
            'user_id' => $both->id,
            'role' => 'box_office',
        ]);

        $kept = $both->createToken('panel')->plainTextToken;

        $this->actingAs($owner)->postJson('/v1/account/closure', [
            'confirm' => $leaving['tenant']->name,
        ])->assertOk();

        $this->assertSame(1, PersonalAccessToken::where('tokenable_id', $both->id)->count());
        $this->assertNotSame('', $kept);
    }

    #[Test]
    public function the_link_still_works_once_the_door_is_shut(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $this->keep($fixture['tenant']);

        $this->actingAs($owner)->postJson('/v1/account/exports')->assertStatus(201);

        $link = $this->actingAs($owner)->postJson('/v1/account/closure', [
            'confirm' => $fixture['tenant']->name,
        ])->assertOk()->json('export.link');

        $this->assertNotNull($link, 'The closure hands back the archive, because nobody can sign in afterwards.');

        app('auth')->forgetGuards();
        $this->get($link)->assertOk();
    }

    #[Test]
    public function a_closed_account_cannot_be_used(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        $this->actingAs($owner)->postJson('/v1/account/closure', [
            'confirm' => $fixture['tenant']->name,
        ])->assertOk();

        app('auth')->forgetGuards();
        $this->actingAs($owner)->getJson('/v1/events')->assertForbidden();
    }

    #[Test]
    public function the_platform_can_open_it_again_inside_the_window(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        $this->actingAs($owner)->postJson('/v1/account/closure', [
            'confirm' => $fixture['tenant']->name,
        ])->assertOk();

        $this->actingAs($this->operator())
            ->patchJson('/v1/admin/tenants/'.$fixture['tenant']->id, ['status' => 'active'])
            ->assertOk();

        $tenant = Tenant::findOrFail($fixture['tenant']->id);

        $this->assertSame('active', $tenant->status);
        // The instant it would have been erased on goes with it. A status flipped back without
        // clearing that would be an account selling tickets with a delete scheduled against it.
        $this->assertNull($tenant->erase_after);
        $this->assertNull($tenant->closed_at);

        $this->artisan('accounts:erase')->assertSuccessful();
        $this->assertNotNull(Tenant::find($fixture['tenant']->id));
    }

    /* ---------------------------------------------------------------------- and then gone */

    #[Test]
    public function an_account_is_erased_once_its_window_has_run_out(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $this->keep($fixture['tenant']);

        $this->actingAs($owner)->postJson('/v1/account/exports')->assertStatus(201);
        $this->actingAs($owner)->postJson('/v1/account/closure', [
            'confirm' => $fixture['tenant']->name,
        ])->assertOk();

        $directory = storage_path('app/private/account-exports/'.$fixture['tenant']->id);
        $this->assertDirectoryExists($directory);

        // Not yet: the window is the whole of what makes closing survivable.
        $this->artisan('accounts:erase')->assertSuccessful();
        $this->assertNotNull(Tenant::find($fixture['tenant']->id));

        Tenant::whereKey($fixture['tenant']->id)->update(['erase_after' => now()->subDay()]);

        $this->artisan('accounts:erase')->assertSuccessful();

        $this->assertNull(Tenant::withTrashed()->find($fixture['tenant']->id));

        // One DELETE, and the whole graph goes with it — which is a promise the database keeps
        // rather than one a loop hopes it covered.
        $this->assertSame(0, Event::withoutGlobalScopes()->count());
        $this->assertDirectoryDoesNotExist($directory);
    }

    #[Test]
    public function the_people_who_worked_there_go_with_it(): void
    {
        $leaving = $this->makeSellableEvent();
        $staying = $this->makeSellableEvent();

        $owner = $this->makeUser($leaving['tenant']);
        $freelance = $this->makeUser($leaving['tenant'], 'box_office');

        // The same person, on somebody else's payroll as well.
        TenantUser::create([
            'tenant_id' => $staying['tenant']->id,
            'user_id' => $freelance->id,
            'role' => 'box_office',
        ]);

        $this->actingAs($owner)->postJson('/v1/account/closure', [
            'confirm' => $leaving['tenant']->name,
        ])->assertOk();

        Tenant::whereKey($leaving['tenant']->id)->update(['erase_after' => now()->subDay()]);

        $this->artisan('accounts:erase')->assertSuccessful();

        // Leaving the staff behind would make the promise a half-measure: the venue's history gone
        // and a list of its people's names and addresses still here, belonging to nobody.
        $this->assertNull(\App\Models\User::find($owner->id));
        $this->assertNotNull(\App\Models\User::find($freelance->id), 'Still somebody else’s colleague.');
    }

    #[Test]
    public function an_archive_nobody_fetched_does_not_sit_there_for_ever(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $this->keep($fixture['tenant']);

        $this->actingAs($owner)->postJson('/v1/account/exports')->assertStatus(201);

        $file = $this->archive($fixture['tenant']);
        $this->assertFileExists($file);

        $this->inTenant($fixture, fn () => AccountExport::query()->update([
            'expires_at' => now()->subMinute(),
        ]));

        $this->artisan('accounts:erase')->assertSuccessful();

        $this->assertFileDoesNotExist($file);
        $this->assertSame(0, $this->inTenant($fixture, fn () => AccountExport::count()));
    }

    #[Test]
    public function an_expired_archive_is_no_longer_offered(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $this->keep($fixture['tenant']);

        $link = $this->actingAs($owner)->postJson('/v1/account/exports')->json('link');

        $this->inTenant($fixture, fn () => AccountExport::query()->update([
            'expires_at' => now()->subMinute(),
        ]));

        // One clock for one promise: the signature and the file expire together, so there is never
        // a link that works against a file that is gone.
        app('auth')->forgetGuards();
        $this->get($link)->assertNotFound();

        $listed = $this->actingAs($owner)->getJson('/v1/account/exports')->json('data.0');
        $this->assertNull($listed['link']);
    }

    /* ------------------------------------------------------------------------------ helpers */

    /** Somebody at the platform, who is the only one who can open a closed account again. */
    private function operator(): \App\Models\User
    {
        $user = \App\Models\User::firstWhere('email', 'operator@platform.test')
            ?? \App\Models\User::factory()->create(['email' => 'operator@platform.test']);

        \App\Models\PlatformAdmin::firstOrCreate(['user_id' => $user->id], ['level' => 'operator']);

        // Sanctum resolves its guard once per test, so a second actingAs would still be answered
        // as the first caller without this.
        app('auth')->forgetGuards();

        return $user;
    }

    private function keep(Tenant $tenant): void
    {
        $this->directories[] = storage_path('app/private/account-exports/'.$tenant->id);
    }

    private function archive(Tenant $tenant): string
    {
        $export = app(TenantContext::class)->runAs(
            $tenant,
            fn () => AccountExport::orderByDesc('created_at')->firstOrFail()
        );

        return storage_path('app/private/'.$export->path);
    }

    /** @param  list<int>  $seats */
    private function buy(array $fixture, array $seats): void
    {
        $this->postJson('http://northgate.test/_store/hold', [
            'event_public_id' => $fixture['event']->public_id,
            'seat_ids' => array_map(fn (int $i) => $fixture['seats'][$i]->id, $seats),
        ])->assertCreated();

        $this->post('http://northgate.test/checkout', [
            'name' => 'Dana Scully',
            'email' => 'dana@example.test',
            'gateway' => 'offline',
        ])->assertRedirect();

        $this->flushSession();
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
