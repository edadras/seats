<?php

namespace Tests\Feature;

use App\Domain\Sites\SiteProvisioner;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\WalletSetting;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;
use ZipArchive;

/**
 * The ticket, in a phone's wallet.
 *
 * The one rule the whole feature turns on is that the credentials belong to the organiser. This
 * platform cannot sign a pass on anybody's behalf — an Apple pass is signed with a certificate
 * issued to a named Apple Developer account, a Google pass with a service account belonging to a
 * named issuer — so a button is offered only where pressing it will actually produce a pass.
 *
 * The certificates below are generated in the test and thrown away with the database. They prove
 * the packaging and the signing, not that Apple would accept them: only Apple's own certificate
 * can do that, and a test that needed one would be a test nobody could run.
 */
class WalletPassTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function a_pass_is_a_signed_zip_with_the_ticket_inside(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->configureApple($fixture['tenant']);
        $this->buy($fixture, [0]);

        $response = $this->get('http://northgate.test/order/'.$this->reference($fixture).'/wallet/apple');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.apple.pkpass');
        // The codes in here open a door. Nothing in between keeps a copy.
        $this->assertStringContainsString('no-store', (string) $response->headers->get('cache-control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('cache-control'));

        $inside = $this->unzip($response->getContent());

        // Apple refuses a pass without an icon and shows a blank card without a logo, and the
        // signature is worth nothing without the manifest it covers.
        $this->assertSame(
            ['icon.png', 'icon@2x.png', 'logo.png', 'manifest.json', 'pass.json', 'signature'],
            array_keys($inside)
        );

        $manifest = json_decode($inside['manifest.json'], true);

        foreach (['pass.json', 'icon.png', 'icon@2x.png', 'logo.png'] as $name) {
            $this->assertSame(sha1($inside[$name]), $manifest[$name], $name.' is not what the manifest says.');
        }

        $pass = json_decode($inside['pass.json'], true);

        $this->assertSame('pass.test.seatmap', $pass['passTypeIdentifier']);
        $this->assertSame('Northgate Theatre', $pass['logoText']);
        $this->assertNotSame('', $pass['barcodes'][0]['message']);
        $this->assertStringStartsWith('seat-', $pass['serialNumber']);
        $this->assertNotEmpty($inside['signature']);
    }

    #[Test]
    public function a_whole_booking_comes_down_as_one_bundle(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->configureApple($fixture['tenant']);
        $this->buy($fixture, [0, 1, 2]);

        $response = $this->get('http://northgate.test/order/'.$this->reference($fixture).'/wallet/apple');

        $response->assertOk();
        // Three separate downloads would be three chances to save two.
        $response->assertHeader('content-type', 'application/vnd.apple.pkpasses');

        $bundle = $this->unzip($response->getContent());

        $this->assertSame(['ticket-1.pkpass', 'ticket-2.pkpass', 'ticket-3.pkpass'], array_keys($bundle));

        $serials = array_map(function (string $pass) {
            return json_decode($this->unzip($pass)['pass.json'], true)['serialNumber'];
        }, array_values($bundle));

        // One card per chair, not three cards for one.
        $this->assertCount(3, array_unique($serials));
    }

    #[Test]
    public function google_gets_a_link_carrying_a_signed_token(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->configureGoogle($fixture['tenant']);
        $this->buy($fixture, [0, 1]);

        $response = $this->get('http://northgate.test/order/'.$this->reference($fixture).'/wallet/google');

        $response->assertRedirect();
        $url = $response->headers->get('location');

        $this->assertStringStartsWith('https://pay.google.com/gp/v/save/', $url);

        $jwt = substr($url, strlen('https://pay.google.com/gp/v/save/'));
        $segments = explode('.', $jwt);

        $this->assertCount(3, $segments);

        $header = json_decode($this->unbase64($segments[0]), true);
        $claims = json_decode($this->unbase64($segments[1]), true);

        $this->assertSame('RS256', $header['alg']);
        $this->assertSame('box-office@northgate.test.iam.gserviceaccount.com', $claims['iss']);
        $this->assertSame('savetowallet', $claims['typ']);
        // A family of four saves four cards from one press.
        $this->assertCount(2, $claims['payload']['eventTicketObjects']);
        $this->assertStringStartsWith('3388000000000000000.', $claims['payload']['eventTicketObjects'][0]['id']);

        // And the signature is really over the two segments in front of it.
        $this->assertSame(1, openssl_verify(
            $segments[0].'.'.$segments[1],
            $this->unbase64($segments[2]),
            openssl_pkey_get_public(openssl_pkey_get_details($this->keypair())['key']),
            OPENSSL_ALGO_SHA256,
        ));
    }

    #[Test]
    public function nothing_is_offered_where_nothing_can_be_signed(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->buy($fixture, [0]);

        $reference = $this->reference($fixture);

        // No button, because an "Add to Apple Wallet" that hands back an error is worse than no
        // offer at all.
        $this->get('http://northgate.test/order/'.$reference)
            ->assertOk()
            ->assertDontSee('Apple Wallet')
            ->assertDontSee('Google Wallet');

        // And the route refuses rather than half-building something.
        $this->get('http://northgate.test/order/'.$reference.'/wallet/apple')->assertStatus(422);
    }

    #[Test]
    public function a_switch_flicked_on_over_nothing_is_still_not_offered(): void
    {
        $fixture = $this->makeSellableEvent();

        app(TenantContext::class)->runAs($fixture['tenant'], function () use ($fixture) {
            WalletSetting::create([
                'tenant_id' => $fixture['tenant']->id,
                'apple_enabled' => true,
                'google_enabled' => true,
            ]);

            // "Enabled" is a switch somebody flicked. Ready is whether flicking it can sign.
            $this->assertSame(['apple' => false, 'google' => false], app(\App\Domain\Wallet\Wallets::class)->offered());
        });
    }

    #[Test]
    public function the_panel_never_hands_a_secret_back(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $keys = $this->certificate();

        $this->actingAs($owner)->putJson('/v1/wallet', [
            'apple_enabled' => true,
            'apple_pass_type_id' => 'pass.test.seatmap',
            'apple_team_id' => 'ABCDE12345',
            'apple_certificate' => $keys['certificate'],
            'apple_key' => $keys['key'],
            'apple_wwdr' => $keys['certificate'],
            'logo_text' => 'Northgate Theatre',
        ])->assertOk();

        $body = $this->actingAs($owner)->getJson('/v1/wallet')->assertOk()->json();

        // Whether each half is there, never what it is.
        $this->assertTrue($body['apple']['has_certificate']);
        $this->assertTrue($body['apple']['ready']);
        $this->assertStringNotContainsString('PRIVATE KEY', json_encode($body));
        $this->assertStringNotContainsString('CERTIFICATE', json_encode($body));

        // Saving a colour must not make somebody paste a certificate again.
        $this->actingAs($owner)->putJson('/v1/wallet', ['logo_text' => 'Northgate'])->assertOk();

        $this->assertTrue($this->actingAs($owner)->getJson('/v1/wallet')->json('apple.has_certificate'));

        // An empty string is the way to take one out, and it is not the same as leaving it alone.
        $this->actingAs($owner)->putJson('/v1/wallet', ['apple_certificate' => ''])->assertOk();

        $this->assertFalse($this->actingAs($owner)->getJson('/v1/wallet')->json('apple.has_certificate'));
        $this->assertFalse($this->actingAs($owner)->getJson('/v1/wallet')->json('apple.ready'));
    }

    #[Test]
    public function the_test_button_signs_something_and_says_what_went_wrong(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $owner = $this->makeUser($fixture['tenant']);

        $this->actingAs($owner)->postJson('/v1/wallet/test', ['platform' => 'apple'])
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('reason', 'apple_wallet_not_set_up');

        $keys = $this->certificate();

        // A certificate and a key that are not a pair: the difference between this and a wrong
        // password is the whole of the debugging.
        $this->actingAs($owner)->putJson('/v1/wallet', [
            'apple_enabled' => true,
            'apple_pass_type_id' => 'pass.test.seatmap',
            'apple_team_id' => 'ABCDE12345',
            'apple_certificate' => $keys['certificate'],
            'apple_key' => $this->certificate(fresh: true)['key'],
            'apple_wwdr' => $keys['certificate'],
        ])->assertOk();

        $this->actingAs($owner)->postJson('/v1/wallet/test', ['platform' => 'apple'])
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('reason', 'apple_signing_failed');

        // Something that is not a certificate at all is a different sentence again.
        $this->actingAs($owner)->putJson('/v1/wallet', [
            'apple_certificate' => 'this is not a certificate',
            'apple_key' => $keys['key'],
        ])->assertOk();

        $this->actingAs($owner)->postJson('/v1/wallet/test', ['platform' => 'apple'])
            ->assertOk()
            ->assertJsonPath('reason', 'apple_certificate_unreadable');

        $this->actingAs($owner)->putJson('/v1/wallet', [
            'apple_certificate' => $keys['certificate'],
            'apple_key' => 'this is not a key',
        ])->assertOk();

        $this->actingAs($owner)->postJson('/v1/wallet/test', ['platform' => 'apple'])
            ->assertOk()
            ->assertJsonPath('reason', 'apple_key_unreadable');

        $this->actingAs($owner)->putJson('/v1/wallet', ['apple_key' => $keys['key']])->assertOk();

        $this->actingAs($owner)->postJson('/v1/wallet/test', ['platform' => 'apple'])
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    #[Test]
    public function the_google_half_says_which_of_its_two_files_is_wrong(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $owner = $this->makeUser($fixture['tenant']);

        $this->actingAs($owner)->postJson('/v1/wallet/test', ['platform' => 'google'])
            ->assertOk()
            ->assertJsonPath('reason', 'google_wallet_not_set_up');

        // A file that is not the one Google gave: no client_email, no private key.
        $this->actingAs($owner)->putJson('/v1/wallet', [
            'google_enabled' => true,
            'google_issuer_id' => '3388000000000000000',
            'google_service_account' => '{"type":"service_account"}',
        ])->assertOk();

        $this->actingAs($owner)->postJson('/v1/wallet/test', ['platform' => 'google'])
            ->assertOk()
            ->assertJsonPath('reason', 'google_service_account_unreadable');

        // Or a file with both fields where the key is not a key.
        $this->actingAs($owner)->putJson('/v1/wallet', [
            'google_service_account' => json_encode([
                'client_email' => 'box-office@northgate.test.iam.gserviceaccount.com',
                'private_key' => 'not a key',
            ]),
        ])->assertOk();

        $this->actingAs($owner)->postJson('/v1/wallet/test', ['platform' => 'google'])
            ->assertOk()
            ->assertJsonPath('reason', 'google_service_account_unreadable');

        openssl_pkey_export($this->keypair(), $private);

        $this->actingAs($owner)->putJson('/v1/wallet', [
            'google_service_account' => json_encode([
                'client_email' => 'box-office@northgate.test.iam.gserviceaccount.com',
                'private_key' => $private,
            ]),
        ])->assertOk();

        $this->actingAs($owner)->postJson('/v1/wallet/test', ['platform' => 'google'])
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    #[Test]
    public function the_credentials_are_behind_the_permission_that_owns_the_account(): void
    {
        $fixture = $this->makeSellableEvent();
        $doorman = $this->makeUser($fixture['tenant'], 'door');

        // What is stored here can mint passes in the organiser's name.
        $this->actingAs($doorman)->getJson('/v1/wallet')->assertForbidden();
        $this->actingAs($doorman)->putJson('/v1/wallet', ['logo_text' => 'Mine now'])->assertForbidden();
        $this->actingAs($doorman)->postJson('/v1/wallet/test', ['platform' => 'apple'])->assertForbidden();
    }

    #[Test]
    public function a_signed_in_buyer_gets_fresh_codes_on_the_pass(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeSite($fixture['tenant']);
        $this->configureApple($fixture['tenant']);
        $this->buy($fixture, [0]);

        $reference = $this->reference($fixture);

        $this->withSession(['seatmap_buyer' => ['name' => 'Amina Farsi', 'email' => 'amina@example.test']]);

        $response = $this->post('http://northgate.test/account/orders/'.$reference.'/wallet/apple');

        // The platform keeps a hash and cannot recover a code, so an account-page pass is a
        // reissue — which is why it is a POST and not a link.
        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.apple.pkpass');

        $pass = json_decode($this->unzip($response->getContent())['pass.json'], true);

        $this->assertNotSame('', $pass['barcodes'][0]['message']);
    }

    /* ------------------------------------------------------------------------------ helpers */

    /** @return array<string, string> filename => contents */
    private function unzip(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'pass');
        file_put_contents($path, $bytes);

        $zip = new ZipArchive;

        $this->assertTrue(true === $zip->open($path), 'That is not a zip.');

        $files = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $files[$name] = (string) $zip->getFromIndex($i);
        }

        $zip->close();
        @unlink($path);
        ksort($files);

        return $files;
    }

    private function unbase64(string $segment): string
    {
        return (string) base64_decode(strtr($segment, '-_', '+/'), true);
    }

    /**
     * A throwaway certificate and its key.
     *
     * Self-signed, generated here and gone with the database. It proves the packaging and the
     * signing; only Apple's own certificate proves a phone would take it.
     *
     * @return array{certificate: string, key: string}
     */
    private function certificate(bool $fresh = false): array
    {
        static $made = null;

        if (! $fresh && $made) {
            return $made;
        }

        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $csr = openssl_csr_new([
            'countryName' => 'GB',
            'organizationName' => 'Northgate Theatre',
            'commonName' => 'Pass Type ID: pass.test.seatmap',
        ], $key, ['digest_alg' => 'sha256']);

        $signed = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);

        openssl_x509_export($signed, $certificate);
        openssl_pkey_export($key, $private);

        $pair = ['certificate' => $certificate, 'key' => $private];

        if (! $fresh) {
            $made = $pair;
        }

        return $pair;
    }

    /** One RSA key for the Google service account, so the signature can be verified against it. */
    private function keypair()
    {
        static $key = null;

        return $key ??= openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
    }

    private function configureApple($tenant): void
    {
        $keys = $this->certificate();

        app(TenantContext::class)->runAs($tenant, fn () => WalletSetting::create([
            'tenant_id' => $tenant->id,
            'apple_enabled' => true,
            'apple_pass_type_id' => 'pass.test.seatmap',
            'apple_team_id' => 'ABCDE12345',
            'apple_certificate' => $keys['certificate'],
            'apple_key' => $keys['key'],
            // Apple's own intermediate travels with the signature. A self-signed stand-in is
            // enough to prove it is carried; only a phone can prove it is the right one.
            'apple_wwdr' => $keys['certificate'],
            'logo_text' => 'Northgate Theatre',
        ]));
    }

    private function configureGoogle($tenant): void
    {
        openssl_pkey_export($this->keypair(), $private);

        app(TenantContext::class)->runAs($tenant, fn () => WalletSetting::create([
            'tenant_id' => $tenant->id,
            'google_enabled' => true,
            'google_issuer_id' => '3388000000000000000',
            'google_service_account' => json_encode([
                'type' => 'service_account',
                'client_email' => 'box-office@northgate.test.iam.gserviceaccount.com',
                'private_key' => $private,
            ]),
        ]));
    }

    private function reference(array $fixture): string
    {
        return app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => \App\Models\ExternalOrder::orderByDesc('created_at')->firstOrFail()->external_order_id
        );
    }

    /** @param  list<int>  $seats */
    private function buy(array $fixture, array $seats): void
    {
        $this->postJson('http://northgate.test/_store/hold', [
            'event_public_id' => $fixture['event']->public_id,
            'seat_ids' => array_map(fn (int $i) => $fixture['seats'][$i]->id, $seats),
        ])->assertCreated();

        $this->post('http://northgate.test/checkout', [
            'name' => 'Amina Farsi',
            'email' => 'amina@example.test',
            'gateway' => 'offline',
        ])->assertRedirect();
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
