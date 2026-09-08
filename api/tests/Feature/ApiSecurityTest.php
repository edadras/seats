<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Support\Signing\HmacSigner;
use App\Support\Signing\PriceSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Str;
use Tests\Support\ActsAsStorefront;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * Threats T3 (price tampering), T4 (replay), T5 (key compromise) and T9 (public surface).
 */
class ApiSecurityTest extends TestCase
{
    use ActsAsStorefront, BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function an_unsigned_request_is_rejected(): void
    {
        $this->sellableOrder('wc_4001');

        $this->postJson('/v1/integrations/woocommerce/orders/wc_4001/confirm', [])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'missing_credentials');
    }

    #[Test]
    public function a_tampered_body_invalidates_the_signature(): void
    {
        $ctx = $this->sellableOrder('wc_4002');
        $path = '/v1/integrations/woocommerce/orders';

        // Sign one payload, send a different one — the body hash is part of the signed string.
        $signedBody = json_encode(['external_order_id' => 'wc_4002b', 'hold_token' => $this->holdToken]);
        $sentBody = json_encode(['external_order_id' => 'wc_4002c', 'hold_token' => $this->holdToken]);

        $headers = $this->signedHeaders(
            $this->storefrontApi['key_id'], $this->storefrontApi['secret'], 'POST', $path, $signedBody
        );

        $this->sendRaw('POST', $path, $sentBody, $headers)
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'invalid_signature');
    }

    #[Test]
    public function a_signature_cannot_be_replayed_onto_a_different_endpoint(): void
    {
        $this->sellableOrder('wc_4003');

        $cancelPath = '/v1/integrations/woocommerce/orders/wc_4003/cancel';
        $confirmPath = '/v1/integrations/woocommerce/orders/wc_4003/confirm';
        $body = '{}';

        // Capture a valid signature for /cancel and aim it at /confirm. The path is signed, so it
        // must not verify.
        $headers = $this->signedHeaders(
            $this->storefrontApi['key_id'], $this->storefrontApi['secret'], 'POST', $cancelPath, $body
        );

        $this->sendRaw('POST', $confirmPath, $body, $headers)
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'invalid_signature');
    }

    #[Test]
    public function replaying_an_identical_signed_request_is_rejected_by_the_nonce_store(): void
    {
        $this->sellableOrder('wc_4004');

        $path = '/v1/integrations/woocommerce/orders/wc_4004/cancel';
        $body = '{}';
        $headers = $this->signedHeaders(
            $this->storefrontApi['key_id'], $this->storefrontApi['secret'], 'POST', $path, $body
        );

        $this->sendRaw('POST', $path, $body, $headers)->assertOk();

        // Byte-identical replay of a captured request.
        $this->sendRaw('POST', $path, $body, $headers)
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'nonce_reused');
    }

    #[Test]
    public function a_stale_timestamp_is_rejected(): void
    {
        $this->sellableOrder('wc_4005');

        $path = '/v1/integrations/woocommerce/orders/wc_4005/cancel';
        $body = '{}';
        $timestamp = (string) (time() - 3600);
        $nonce = Str::random(24);

        $this->sendRaw('POST', $path, $body, [
            'X-Seatmap-Key' => $this->storefrontApi['key_id'],
            'X-Seatmap-Timestamp' => $timestamp,
            'X-Seatmap-Nonce' => $nonce,
            'X-Seatmap-Signature' => HmacSigner::sign(
                $this->storefrontApi['secret'],
                HmacSigner::canonicalString('POST', $path, $timestamp, $nonce, $body),
            ),
        ])->assertUnauthorized()->assertJsonPath('error.code', 'stale_timestamp');
    }

    #[Test]
    public function a_revoked_key_stops_working_while_a_rotated_one_keeps_going(): void
    {
        $ctx = $this->sellableOrder('wc_4006');
        $original = $this->storefrontApi;

        // Rotate: the new key is issued alongside the old so a site can be updated with no gap.
        $rotated = $this->asTenant($ctx['tenant'], fn () => ApiKey::issue($original['client'], 'rotated'));

        $newKey = ['key_id' => $rotated['model']->key_id, 'secret' => $rotated['secret']];

        $this->storefrontApi = $newKey;
        $this->storefront('GET', '/v1/integrations/woocommerce/orders/wc_4006')->assertOk();

        $this->storefrontApi = $original;
        $this->storefront('GET', '/v1/integrations/woocommerce/orders/wc_4006')->assertOk();

        // Now revoke the original. It must stop immediately; the rotated key must be unaffected.
        $this->asTenant($ctx['tenant'], fn () => ApiKey::where('key_id', $original['key_id'])
            ->update(['revoked_at' => now()]));

        $this->storefront('GET', '/v1/integrations/woocommerce/orders/wc_4006')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'invalid_key');

        $this->storefrontApi = $newKey;
        $this->storefront('GET', '/v1/integrations/woocommerce/orders/wc_4006')->assertOk();
    }

    #[Test]
    public function the_hold_price_comes_from_the_server_not_the_request(): void
    {
        ['event' => $event, 'seats' => $seats] = $this->makeSellableEvent(amount: 4000);

        // A hostile client sending its own prices. They are simply not read.
        $hold = $this->postJson("/v1/embed/events/{$event->public_id}/holds", [
            'seat_ids' => [$seats[0]->id],
            'session_id' => 'attacker',
            'amount' => 1,
            'total_amount' => 1,
            'price' => 1,
        ])->assertCreated();

        $this->assertSame(4000, $hold->json('total_amount'));
        $this->assertSame(4000, $hold->json('seats.0.amount'));
    }

    #[Test]
    public function a_tampered_price_snapshot_fails_signature_verification(): void
    {
        ['event' => $event, 'seats' => $seats] = $this->makeSellableEvent(amount: 4000);

        $hold = $this->postJson("/v1/embed/events/{$event->public_id}/holds", [
            'seat_ids' => [$seats[0]->id],
            'session_id' => 'buyer',
        ])->assertCreated();

        $signer = app(PriceSigner::class);
        $payload = $hold->json('price_snapshot.payload');
        $signature = $hold->json('price_snapshot.signature');

        // The genuine snapshot verifies and says 4000.
        $decoded = $signer->decode($payload, $signature);
        $this->assertSame(4000, $decoded['total_amount']);

        // Rewriting the amount and re-encoding — without the key — must not verify. This is what
        // stops a storefront being talked into charging one cent.
        $forged = $decoded;
        $forged['total_amount'] = 1;
        $forgedPayload = rtrim(strtr(base64_encode(json_encode($forged)), '+/', '-_'), '=');

        $this->assertFalse($signer->verify($forgedPayload, $signature));
        $this->assertNull($signer->decode($forgedPayload, $signature));
    }

    #[Test]
    public function public_endpoints_do_not_expose_tenant_internals_or_ticket_tokens(): void
    {
        $ctx = $this->sellableOrder('wc_4007');
        $this->storefront('POST', '/v1/integrations/woocommerce/orders/wc_4007/confirm')->assertOk();

        $body = $this->getJson("/v1/embed/events/{$ctx['event']->public_id}")->assertOk()->getContent();

        foreach (['tenant_id', 'token_hash', 'TKT', 'secret', 'api_client'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body);
        }

        $availability = $this->getJson("/v1/embed/events/{$ctx['event']->public_id}/availability")->getContent();
        $this->assertStringNotContainsString('tenant_id', $availability);
    }

    #[Test]
    public function a_draft_event_is_not_reachable_through_the_public_widget(): void
    {
        $ctx = $this->makeSellableEvent();
        $this->asTenant($ctx['tenant'], fn () => $ctx['event']->update(['status' => 'draft']));

        // Not 403: a distinct status would confirm the id is real.
        $this->getJson("/v1/embed/events/{$ctx['event']->public_id}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    #[Test]
    public function a_suspended_tenant_cannot_transact(): void
    {
        $ctx = $this->sellableOrder('wc_4008');

        $this->asTenant($ctx['tenant'], fn () => $ctx['tenant']->update(['status' => 'suspended']));

        $this->storefront('POST', '/v1/integrations/woocommerce/orders/wc_4008/confirm')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'client_disabled');

        $this->getJson("/v1/embed/events/{$ctx['event']->public_id}")->assertNotFound();
    }

    #[Test]
    public function a_viewer_cannot_change_anything(): void
    {
        $ctx = $this->makeSellableEvent();
        $viewer = $this->makeUser($ctx['tenant'], 'viewer');

        $token = $this->postJson('/v1/auth/login', [
            'email' => $viewer->email, 'password' => 'password',
        ])->assertOk()->json('token');

        // Reading is fine for a viewer...
        $this->withToken($token)->getJson("/v1/events/{$ctx['event']->id}")->assertOk();

        // ...writing is not.
        $this->withToken($token)->patchJson("/v1/events/{$ctx['event']->id}", ['name' => 'Changed'])
            ->assertForbidden();
        $this->withToken($token)->postJson("/v1/seat-maps/{$ctx['map']->id}/publish")->assertForbidden();
    }

    #[Test]
    public function login_does_not_reveal_whether_an_account_exists(): void
    {
        $ctx = $this->makeSellableEvent();
        $user = $this->makeUser($ctx['tenant']);

        $wrongPassword = $this->postJson('/v1/auth/login', [
            'email' => $user->email, 'password' => 'not-the-password',
        ])->assertUnauthorized();

        $noSuchUser = $this->postJson('/v1/auth/login', [
            'email' => 'nobody@example.test', 'password' => 'not-the-password',
        ])->assertUnauthorized();

        $this->assertSame($wrongPassword->json('error.code'), $noSuchUser->json('error.code'));
        $this->assertSame($wrongPassword->json('error.message'), $noSuchUser->json('error.message'));
    }

    private function sendRaw(string $method, string $path, string $body, array $headers): \Illuminate\Testing\TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call($method, $path, [], [], [], $server, $body);
    }
}
