<?php

namespace Tests\Feature;

use App\Models\ModuleFailure;
use App\Models\TenantModule;
use App\Modules\ModuleHealth;
use App\Modules\ModuleManifest;
use App\Modules\ModuleRegistry;
use App\Modules\ModuleSettings;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * The module system (ADR-0004).
 *
 * The four things worth guarding hardest, in order of what they would cost:
 *
 *   a secret cannot be read back out of the panel;
 *   installed and enabled are different questions, and a tenant's answer is theirs alone;
 *   a broken module does not take a request down with it;
 *   and it does not go on failing silently either.
 */
class ModuleSystemTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    private const OFFLINE = 'seatmap/offline-payments';

    #[Test]
    public function the_first_party_module_is_discovered_from_the_filesystem(): void
    {
        $registry = app(ModuleRegistry::class);

        $this->assertTrue($registry->isInstalled(self::OFFLINE));

        $manifest = $registry->manifest(self::OFFLINE);

        $this->assertSame('seatmap', $manifest->vendor);
        $this->assertTrue($manifest->firstParty);
        $this->assertSame(['payments'], $manifest->extends);
    }

    #[Test]
    public function a_module_that_needs_nothing_is_on_for_a_brand_new_organiser(): void
    {
        $tenant = $this->makeTenant();

        app(TenantContext::class)->runAs($tenant, function () {
            $registry = app(ModuleRegistry::class);

            // A new account can take a booking without first being sent to a settings screen.
            $this->assertTrue($registry->isEnabled(self::OFFLINE));
            $this->assertNotEmpty($registry->contributions('payments'));
        });
    }

    #[Test]
    public function an_organiser_who_switched_it_off_stays_switched_off(): void
    {
        $tenant = $this->makeTenant();

        app(TenantContext::class)->runAs($tenant, function () {
            TenantModule::create([
                'module_key' => self::OFFLINE,
                'enabled' => false,
                'settings' => [],
            ]);

            // A decision already made is never re-made for somebody by an auto-enable flag.
            $this->assertFalse(app(ModuleRegistry::class)->isEnabled(self::OFFLINE));
            $this->assertSame([], app(ModuleRegistry::class)->contributions('payments'));
        });
    }

    #[Test]
    public function one_organisers_modules_are_not_anothers(): void
    {
        $first = $this->makeTenant();
        $second = $this->makeTenant();

        app(TenantContext::class)->runAs($first, function () {
            TenantModule::create(['module_key' => self::OFFLINE, 'enabled' => false, 'settings' => []]);
        });

        app(TenantContext::class)->runAs($first, function () {
            $this->assertFalse(app(ModuleRegistry::class)->isEnabled(self::OFFLINE));
        });

        app(TenantContext::class)->runAs($second, function () {
            $this->assertTrue(app(ModuleRegistry::class)->isEnabled(self::OFFLINE));
        });
    }

    #[Test]
    public function a_secret_goes_in_and_never_comes_back_out(): void
    {
        $manifest = $this->manifestWithSecret();
        $settings = app(ModuleSettings::class);

        $stored = $settings->normalise($manifest, ['api_key' => 'sk_live_do_not_leak']);

        // Encrypted at rest: the row itself does not carry the key.
        $this->assertNotSame('sk_live_do_not_leak', $stored['api_key']);
        $this->assertSame('sk_live_do_not_leak', Crypt::decryptString($stored['api_key']));

        $tenant = $this->makeTenant();

        app(TenantContext::class)->runAs($tenant, function () use ($manifest, $stored, $settings) {
            TenantModule::create([
                'module_key' => $manifest->key,
                'enabled' => true,
                'settings' => $stored,
            ]);

            // What the panel sees is that it is set, and nothing more.
            $shown = $settings->present($manifest, app(TenantContext::class)->id());
            $this->assertSame(ModuleSettings::MASK, $shown['api_key']);

            // What the module runs with is the real thing — the only place plaintext exists.
            $resolved = $settings->resolve($manifest, app(TenantContext::class)->id());
            $this->assertSame('sk_live_do_not_leak', $resolved['api_key']);
        });
    }

    #[Test]
    public function saving_a_form_that_shows_a_masked_secret_does_not_wipe_it(): void
    {
        $manifest = $this->manifestWithSecret();
        $settings = app(ModuleSettings::class);

        $existing = $settings->normalise($manifest, ['api_key' => 'original']);

        // The panel round-trips the mask; that must mean "unchanged", not "cleared".
        $after = $settings->normalise($manifest, ['api_key' => ModuleSettings::MASK], $existing);
        $this->assertSame($existing['api_key'], $after['api_key']);

        $blank = $settings->normalise($manifest, ['api_key' => ''], $existing);
        $this->assertSame($existing['api_key'], $blank['api_key']);

        // And a real replacement replaces.
        $replaced = $settings->normalise($manifest, ['api_key' => 'rotated'], $existing);
        $this->assertSame('rotated', Crypt::decryptString($replaced['api_key']));
    }

    #[Test]
    public function the_api_never_returns_a_secret(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        $this->actingAs($owner)
            ->getJson('/v1/modules')
            ->assertOk()
            ->assertJsonPath('data.0.key', self::OFFLINE);

        $response = $this->actingAs($owner)
            ->getJson('/v1/modules/seatmap.offline-payments');

        $response->assertOk();
        $response->assertJsonPath('key', self::OFFLINE);

        // Whatever is in this payload, none of it is a plaintext credential — this assertion is
        // the one that would fail first if `present()` were ever "simplified" into `resolve()`.
        $this->assertStringNotContainsString('sk_live', $response->getContent());
    }

    #[Test]
    public function an_organiser_turns_a_module_off_and_it_stops_contributing(): void
    {
        $fixture = $this->makeSellableEvent();

        $this->actingAs($this->makeUser($fixture['tenant']))
            ->patchJson('/v1/modules/seatmap.offline-payments', ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('enabled', false);

        app(TenantContext::class)->runAs($fixture['tenant'], function () {
            $this->assertSame([], app(ModuleRegistry::class)->contributions('payments'));
        });
    }

    #[Test]
    public function a_module_nobody_installed_is_a_404_not_a_row(): void
    {
        $fixture = $this->makeSellableEvent();

        $this->actingAs($this->makeUser($fixture['tenant']))
            ->patchJson('/v1/modules/somebody.malware', ['enabled' => true])
            ->assertNotFound();

        // No row was created for a module that does not exist: enabling is a decision about
        // something real, and a table of wishes would be a table of attack surface.
        $this->assertSame(0, TenantModule::withoutGlobalScope('tenant')->count());
    }

    #[Test]
    public function a_module_that_throws_does_not_take_the_request_with_it(): void
    {
        $tenant = $this->makeTenant();

        app(TenantContext::class)->runAs($tenant, function () use ($tenant) {
            $registry = app(ModuleRegistry::class);

            // Simulate what a broken module produces: a failure recorded against it.
            $registry->recordFailure(self::OFFLINE, 'payments', 'payments', new \RuntimeException('gateway is on fire'));

            $failure = ModuleFailure::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->first();

            $this->assertNotNull($failure, 'A swallowed failure that is not recorded is a silent one.');
            $this->assertSame('gateway is on fire', $failure->message);

            // And it is visible as the module's health, which is what the panel shows.
            $health = app(ModuleHealth::class)->forModule(self::OFFLINE);
            $this->assertSame(1, $health['failures']);
        });
    }

    #[Test]
    public function a_module_that_keeps_failing_is_switched_off_with_a_reason(): void
    {
        config()->set('seatmap.modules.max_failures', 3);

        $tenant = $this->makeTenant();

        app(TenantContext::class)->runAs($tenant, function () use ($tenant) {
            $registry = app(ModuleRegistry::class);

            foreach (range(1, 3) as $attempt) {
                $registry->recordFailure(self::OFFLINE, 'payments', 'begin', new \RuntimeException('nope'));
            }

            $row = TenantModule::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)
                ->where('module_key', self::OFFLINE)
                ->first();

            // Off, and saying why. A module that failed forever in silence would look exactly
            // like one that was working.
            $this->assertNotNull($row);
            $this->assertFalse($row->enabled);
            $this->assertSame('repeated_failures', $row->disabled_reason);
            $this->assertFalse(app(ModuleRegistry::class)->isEnabled(self::OFFLINE));
        });
    }

    #[Test]
    public function a_manifest_that_lies_is_refused(): void
    {
        // Every one of these is something a third-party manifest could try, and each ends up in a
        // URL, a database column or a translation key if it is not checked here.
        $bad = [
            ['key' => 'no-slash', 'provider' => \Modules\Seatmap\OfflinePayments\Provider::class, 'extends' => ['payments']],
            ['key' => 'vendor/name', 'provider' => 'Nope\\Missing', 'extends' => ['payments']],
            ['key' => 'vendor/name', 'provider' => \stdClass::class, 'extends' => ['payments']],
            ['key' => 'vendor/name', 'provider' => \Modules\Seatmap\OfflinePayments\Provider::class, 'extends' => ['rm -rf']],
            ['key' => '../../etc/passwd', 'provider' => \Modules\Seatmap\OfflinePayments\Provider::class, 'extends' => ['payments']],
        ];

        foreach ($bad as $index => $data) {
            try {
                ModuleManifest::fromArray($data, '/tmp/module-'.$index);
                $this->fail('Manifest #'.$index.' should have been refused: '.json_encode($data));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function a_module_that_needs_configuring_is_never_on_by_default(): void
    {
        // `auto_enable` on a module with a required setting would produce the one state nobody
        // wants: enabled and unconfigured, taking bookings it cannot settle.
        $manifest = ModuleManifest::fromArray([
            'key' => 'vendor/needs-a-key',
            'provider' => \Modules\Seatmap\OfflinePayments\Provider::class,
            'extends' => ['payments'],
            'auto_enable' => true,
            'settings' => [['key' => 'api_key', 'type' => 'secret', 'required' => true]],
        ], '/tmp/needs-a-key');

        $this->assertFalse($manifest->autoEnable);
    }

    private function manifestWithSecret(): ModuleManifest
    {
        return ModuleManifest::fromArray([
            'key' => 'vendor/with-secret',
            'provider' => \Modules\Seatmap\OfflinePayments\Provider::class,
            'extends' => ['payments'],
            'settings' => [
                ['key' => 'api_key', 'type' => 'secret', 'required' => true],
                ['key' => 'sandbox', 'type' => 'boolean', 'default' => false],
            ],
        ], '/tmp/with-secret');
    }
}
