<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\IdentityProvider;
use App\Models\PlatformAdmin;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * Signing in the way a large venue already does, and scoping a key to what it is for.
 *
 * Two halves of one idea: who may do what, said once rather than implied. The claims worth holding
 * down are the ones that fail silently — a password that still works after an account said it
 * should not, somebody the provider vouched for who was never invited here, a key that can refund
 * because nobody thought to stop it.
 */
class SingleSignOnTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    /* ------------------------------------------------------------------ setting it up */

    #[Test]
    public function saving_reads_the_endpoints_out_of_the_issuer(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        $this->pretendProvider();

        $body = $this->actingAs($owner)->putJson('/v1/sso', [
            'label' => 'Trinity College',
            'issuer' => 'https://login.example.org/',
            'client_id' => 'seatmap',
            'client_secret' => 'shhh',
        ])->assertOk()->json();

        $this->assertTrue($body['configured']);
        $this->assertSame('https://login.example.org/authorize', $body['endpoints']['authorize']);
        $this->assertSame('https://login.example.org/token', $body['endpoints']['token']);
        $this->assertTrue($body['has_secret']);

        // The secret has no way out, in any shape. There is no reading one back, only replacing it.
        $this->assertStringNotContainsString('shhh', json_encode($body));

        // Both addresses are composed rather than described: an organiser copying a URL out of
        // prose gets it wrong once in five, and the failure says nothing useful.
        $this->assertStringEndsWith('/sso/return', $body['redirect_uri']);
        $this->assertStringEndsWith('/sso/'.$fixture['tenant']->slug, $body['sign_in_url']);
    }

    #[Test]
    public function an_address_that_is_not_a_provider_is_refused_while_somebody_is_looking(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        // Two addresses rather than two fakes for one: a stub registered against a pattern that
        // already matched would never be reached, and the test would pass on the wrong one.
        Http::fake([
            'https://answers.example.org/*' => Http::response('not json', 200),
            'https://silent.example.org/*' => Http::response('', 500),
        ]);

        $this->actingAs($owner)->putJson('/v1/sso', [
            'label' => 'Somewhere',
            'issuer' => 'https://answers.example.org',
            'client_id' => 'seatmap',
            'client_secret' => 'shhh',
        ])->assertStatus(422)->assertJsonPath('error.code', 'issuer_not_openid');

        $this->actingAs($owner)->putJson('/v1/sso', [
            'label' => 'Somewhere',
            'issuer' => 'https://silent.example.org',
            'client_id' => 'seatmap',
            'client_secret' => 'shhh',
        ])->assertStatus(422)->assertJsonPath('error.code', 'issuer_unreachable');

        $this->assertFalse($this->actingAs($owner)->getJson('/v1/sso')->json('configured'));
    }

    #[Test]
    public function an_empty_secret_keeps_the_one_that_is_there(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        $this->configure($fixture);

        $this->actingAs($owner)->putJson('/v1/sso', [
            'label' => 'Trinity College (staff)',
            'issuer' => 'https://login.example.org',
            'client_id' => 'seatmap',
        ])->assertOk()->assertJsonPath('label', 'Trinity College (staff)');

        // A secret that had to be retyped to change a label is a secret that ends up in a notebook.
        $this->assertSame('shhh', $this->provider($fixture)->client_secret);
    }

    #[Test]
    public function only_somebody_who_may_manage_the_account_may_decide_how_people_sign_in(): void
    {
        $fixture = $this->makeSellableEvent();
        $clerk = $this->makeUser($fixture['tenant'], 'box_office');

        $this->actingAs($clerk)->getJson('/v1/sso')->assertForbidden();
        $this->actingAs($clerk)->putJson('/v1/sso', [])->assertForbidden();
        $this->actingAs($clerk)->deleteJson('/v1/sso')->assertForbidden();
    }

    /* ------------------------------------------------------------------ signing in */

    #[Test]
    public function the_start_sends_somebody_to_their_own_provider(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->configure($fixture);

        $away = $this->get('/sso/'.$fixture['tenant']->slug)->assertRedirect();
        $url = $away->headers->get('Location');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith('https://login.example.org/authorize?', $url);
        $this->assertSame('seatmap', $query['client_id']);
        $this->assertSame('code', $query['response_type']);
        $this->assertStringEndsWith('/sso/return', $query['redirect_uri']);
        // Proof that whoever redeems the code is whoever asked for it.
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertNotEmpty($query['code_challenge']);
        $this->assertNotEmpty($query['state']);
    }

    #[Test]
    public function an_account_nobody_has_heard_of_and_one_without_a_provider_answer_the_same(): void
    {
        $fixture = $this->makeSellableEvent();

        // Telling them apart would answer a question about other people's organisations that
        // nobody signed in has any business asking.
        $this->get('/sso/nobody-here')->assertRedirect('/?sso=unavailable');
        $this->get('/sso/'.$fixture['tenant']->slug)->assertRedirect('/?sso=unavailable');
    }

    #[Test]
    public function coming_back_signs_a_member_in(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        $owner->forceFill(['email' => 'dana@example.org', 'name' => 'D. Scully'])->save();

        $this->configure($fixture);

        $handoff = $this->walkBack($fixture, 'dana@example.org', 'Dana Scully');

        $signedIn = $this->postJson('/v1/auth/sso/claim', [
            'handoff' => $handoff,
            'device_name' => 'panel',
        ])->assertOk()->json();

        $this->assertNotEmpty($signedIn['token']);
        $this->assertSame($fixture['tenant']->id, $signedIn['tenant']['id']);
        $this->assertSame('owner', $signedIn['role']);
        // The second factor belongs to the provider now, so the panel is never sent to set one up.
        $this->assertFalse($signedIn['must_set_up_two_factor']);

        // The directory is the thing this account decided to believe about its people.
        $this->assertSame('Dana Scully', User::findOrFail($owner->id)->name);

        // And the handle is worth nothing twice.
        $this->postJson('/v1/auth/sso/claim', ['handoff' => $handoff])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'sso_expired');
    }

    #[Test]
    public function somebody_the_provider_vouches_for_who_was_never_invited_is_refused(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->makeUser($fixture['tenant']);
        $this->configure($fixture);

        // A university directory is forty thousand people and a box office is eleven of them.
        $this->get($this->walkBackUrl($fixture, 'stranger@example.org'))
            ->assertRedirect('/?sso=stranger');

        $this->assertSame(1, app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => \App\Models\AuditLog::where('action', 'sso.refused')->count()
        ));
    }

    #[Test]
    public function an_address_the_provider_has_not_verified_is_not_an_address(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $owner->forceFill(['email' => 'dana@example.org'])->save();

        $this->configure($fixture);

        $this->pretendSignIn('dana@example.org', 'Dana Scully', verified: false);

        $this->get($this->started($fixture))->assertRedirect('/?sso=refused');
    }

    #[Test]
    public function a_callback_that_arrives_twice_works_once(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $owner->forceFill(['email' => 'dana@example.org'])->save();

        $this->configure($fixture);
        $url = $this->started($fixture);

        $this->pretendSignIn('dana@example.org', 'Dana Scully');

        $this->get($url)->assertRedirect();
        // The state is pulled from the cache by the first request that presents it, so a link
        // followed twice — a refresh, a back button, somebody else reading a log — works once.
        $this->get($url)->assertRedirect('/?sso=refused');
    }

    /* ------------------------------------------------------------------ locking the door */

    #[Test]
    public function a_password_stops_working_when_the_account_says_so(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $owner->forceFill(['email' => 'owner@example.org', 'password' => Hash::make('correct horse')])->save();

        $this->configure($fixture, required: true);

        $this->postJson('/v1/auth/login', [
            'email' => 'owner@example.org',
            'password' => 'correct horse',
        ])->assertStatus(403)->assertJsonPath('error.code', 'sso_required');

        /*
         * And the wrong password still answers the wrong-password way.
         *
         * The check is deliberately after the password rather than before it: answering "that
         * account uses single sign-on" to anybody who types an address would turn this endpoint
         * into a way of asking which organisations use which provider.
         */
        $this->postJson('/v1/auth/login', [
            'email' => 'owner@example.org',
            'password' => 'not it',
        ])->assertStatus(401)->assertJsonPath('error.code', 'invalid_credentials');
    }

    #[Test]
    public function a_provider_that_is_switched_off_does_not_lock_anybody_out(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $owner->forceFill(['email' => 'owner@example.org', 'password' => Hash::make('correct horse')])->save();

        $this->configure($fixture, required: true);

        app(TenantContext::class)->runAs($fixture['tenant'], fn () => IdentityProvider::query()
            ->update(['enabled' => false]));

        // Required means nothing without a provider that works: the alternative is an account with
        // no way in at all because somebody ticked two boxes in the wrong order.
        $this->postJson('/v1/auth/login', [
            'email' => 'owner@example.org',
            'password' => 'correct horse',
        ])->assertOk();
    }

    #[Test]
    public function the_platform_can_let_a_locked_out_account_back_in(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $owner->forceFill(['email' => 'owner@example.org', 'password' => Hash::make('correct horse')])->save();

        $this->configure($fixture, required: true);

        $operator = User::factory()->create(['email' => 'operator@platform.test']);
        PlatformAdmin::firstOrCreate(['user_id' => $operator->id], ['level' => 'operator']);
        app('auth')->forgetGuards();

        $this->actingAs($operator)
            ->patchJson('/v1/admin/tenants/'.$fixture['tenant']->id, ['sso' => 'off'])
            ->assertOk();

        app('auth')->forgetGuards();

        // The way back is the platform rather than a break-glass password, because a password that
        // still worked for one person would be the password an attacker goes looking for.
        $this->postJson('/v1/auth/login', [
            'email' => 'owner@example.org',
            'password' => 'correct horse',
        ])->assertOk();
    }

    #[Test]
    public function removing_it_gives_the_passwords_back(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $owner->forceFill(['email' => 'owner@example.org', 'password' => Hash::make('correct horse')])->save();

        $this->configure($fixture, required: true);

        $this->actingAs($owner)->deleteJson('/v1/sso')->assertOk()->assertJsonPath('configured', false);

        app('auth')->forgetGuards();

        $this->postJson('/v1/auth/login', [
            'email' => 'owner@example.org',
            'password' => 'correct horse',
        ])->assertOk();
    }

    /* ------------------------------------------------------------------ what a key is for */

    #[Test]
    public function a_key_that_may_sell_may_not_refund(): void
    {
        $fixture = $this->makeSellableEvent();
        $api = $this->makeApiClient($fixture['tenant']);

        $key = app(TenantContext::class)->runAs($fixture['tenant'], fn () => ApiKey::issue(
            \App\Models\ApiClient::findOrFail($api['client']->id),
            'shop',
            null,
            ['orders.read', 'orders.write'],
        ));

        $scoped = ['key_id' => $key['model']->key_id, 'secret' => $key['secret']];
        $reference = 'wc_'.Str::lower(Str::random(10));

        $hold = $this->postJson("/v1/embed/events/{$fixture['event']->public_id}/holds", [
            'seat_ids' => [$fixture['seats'][0]->id],
            'session_id' => 'sess_'.Str::random(8),
        ])->assertCreated()->json();

        $this->signedPost($scoped, '/v1/integrations/woocommerce/orders', [
            'external_order_id' => $reference,
            'hold_token' => $hold['hold_token'],
        ])->assertCreated();

        $this->signedPost($scoped, '/v1/integrations/woocommerce/orders/'.$reference.'/confirm', [
            'buyer' => ['name' => 'Dana Scully', 'email' => 'dana@example.test'],
        ])->assertOk();

        // The refusal is a refusal rather than a 404: the caller holds a working credential and is
        // being told this key may not do this, which they can act on by issuing themselves another.
        $this->signedPost($scoped, '/v1/integrations/woocommerce/orders/'.$reference.'/refund', [])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'key_scope');
    }

    #[Test]
    public function a_key_from_before_scopes_existed_may_still_do_everything(): void
    {
        $fixture = $this->makeSellableEvent();
        $api = $this->makeApiClient($fixture['tenant']);
        $reference = 'wc_'.Str::lower(Str::random(10));

        $hold = $this->postJson("/v1/embed/events/{$fixture['event']->public_id}/holds", [
            'seat_ids' => [$fixture['seats'][0]->id],
            'session_id' => 'sess_'.Str::random(8),
        ])->assertCreated()->json();

        $this->signedPost($api, '/v1/integrations/woocommerce/orders', [
            'external_order_id' => $reference,
            'hold_token' => $hold['hold_token'],
        ])->assertCreated();

        $this->signedPost($api, '/v1/integrations/woocommerce/orders/'.$reference.'/confirm', [
            'buyer' => ['name' => 'Dana Scully', 'email' => 'dana@example.test'],
        ])->assertOk();

        // Narrowing these silently would have taken a working shop off sale on the day scopes
        // were deployed, and the shop would have had no idea why.
        $this->signedPost($api, '/v1/integrations/woocommerce/orders/'.$reference.'/refund', [])
            ->assertOk();
    }

    #[Test]
    public function a_key_is_issued_with_what_it_is_for_and_the_screen_says_so(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);
        $api = $this->makeApiClient($fixture['tenant']);

        $issued = $this->actingAs($owner)
            ->postJson('/v1/api-clients/'.$api['client']->id.'/keys', [
                'label' => 'The main shop',
                'scopes' => ['orders.write', 'orders.read'],
            ])->assertStatus(201)->json();

        // Tidied into the catalogue's own order, so two keys with the same powers read the same.
        $this->assertSame(['orders.read', 'orders.write'], $issued['scopes']);

        $listed = $this->actingAs($owner)->getJson('/v1/api-clients')->assertOk()->json('data.0.keys');
        $scopes = array_column($listed, 'scopes');

        $this->assertContains(['orders.read', 'orders.write'], $scopes);
        $this->assertContains(null, $scopes, 'The one issued with the client may still do everything.');

        // A scope nobody has heard of is refused rather than stored looking as though it grants
        // something.
        $this->actingAs($owner)
            ->postJson('/v1/api-clients/'.$api['client']->id.'/keys', ['scopes' => ['everything']])
            ->assertStatus(422);
    }

    /* ------------------------------------------------------------------------------ helpers */

    /** The issuer's own well-known document, and nothing else. */
    private function pretendProvider(): void
    {
        Http::fake([
            '*/.well-known/openid-configuration' => Http::response([
                'issuer' => 'https://login.example.org',
                'authorization_endpoint' => 'https://login.example.org/authorize',
                'token_endpoint' => 'https://login.example.org/token',
                'userinfo_endpoint' => 'https://login.example.org/userinfo',
            ]),
        ]);
    }

    /** The whole provider: discovery, the code exchange, and who the person is. */
    private function pretendSignIn(string $email, ?string $name = null, bool $verified = true): void
    {
        Http::fake([
            '*/.well-known/openid-configuration' => Http::response([
                'authorization_endpoint' => 'https://login.example.org/authorize',
                'token_endpoint' => 'https://login.example.org/token',
                'userinfo_endpoint' => 'https://login.example.org/userinfo',
            ]),
            'https://login.example.org/token' => Http::response(['access_token' => 'at_'.Str::random(10)]),
            'https://login.example.org/userinfo' => Http::response(array_filter([
                'email' => $email,
                'email_verified' => $verified,
                'name' => $name,
            ], fn ($value) => null !== $value)),
        ]);
    }

    private function configure(array $fixture, bool $required = false): void
    {
        $this->pretendProvider();

        $owner = $this->makeUser($fixture['tenant'], 'admin');

        $this->actingAs($owner)->putJson('/v1/sso', [
            'label' => 'Trinity College',
            'issuer' => 'https://login.example.org',
            'client_id' => 'seatmap',
            'client_secret' => 'shhh',
            'required' => $required,
        ])->assertOk();

        app('auth')->forgetGuards();
    }

    /** Start a sign-in and come back with the state the provider would carry. */
    private function started(array $fixture): string
    {
        $away = $this->get('/sso/'.$fixture['tenant']->slug)->assertRedirect();

        parse_str((string) parse_url((string) $away->headers->get('Location'), PHP_URL_QUERY), $query);

        return '/sso/return?state='.$query['state'].'&code=code_'.Str::random(8);
    }

    private function walkBackUrl(array $fixture, string $email, ?string $name = null): string
    {
        $url = $this->started($fixture);
        $this->pretendSignIn($email, $name);

        return $url;
    }

    /** The whole round trip, ending with the one-time handle the panel would be given. */
    private function walkBack(array $fixture, string $email, ?string $name = null): string
    {
        $back = $this->get($this->walkBackUrl($fixture, $email, $name))->assertRedirect();

        parse_str((string) parse_url((string) $back->headers->get('Location'), PHP_URL_QUERY), $query);

        return (string) ($query['handoff'] ?? '');
    }

    private function provider(array $fixture): IdentityProvider
    {
        return app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => IdentityProvider::firstOrFail()
        );
    }

    private function signedPost(array $api, string $path, array $payload)
    {
        $body = json_encode($payload);

        return $this->call(
            'POST', $path, [], [], [],
            $this->serverHeaders($this->signedHeaders($api['key_id'], $api['secret'], 'POST', $path, $body)),
            $body,
        );
    }
}
