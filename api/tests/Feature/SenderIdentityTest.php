<?php

namespace Tests\Feature;

use App\Domain\Messaging\MessageDispatcher;
use App\Domain\Messaging\SenderIdentity;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * Who a buyer's confirmation appears to come from.
 *
 * Every email this platform had ever sent left from one address, for every venue on it: a buyer who
 * bought a ticket from Northgate Theatre got a confirmation from a name they had never heard of,
 * and replying to it reached nobody.
 *
 * The claim under test is deliberately in three parts, because deliverability makes it three. The
 * **name** is the venue's at once — there is nothing to prove about a label. The **reply address**
 * is the venue's once a code sent to it has been typed back, because otherwise an organiser could
 * point a venue's complaints at a stranger. The **From address** stays the platform's unless the
 * operator has authorised that domain, because sending as a domain whose SPF does not list this
 * server is how tickets end up in spam — and a ticketing platform that quietly wrecks
 * deliverability has done something worse than not offering the option.
 */
class SenderIdentityTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function an_account_that_has_said_nothing_still_gets_its_own_name(): void
    {
        $tenant = $this->makeTenant('Northgate Theatre');

        $from = app(SenderIdentity::class)->current($tenant);

        // The account's name rather than the platform's brand: better than nothing, and it costs
        // no deliverability at all.
        $this->assertSame('Northgate Theatre', $from['name']);
        $this->assertSame(config('mail.from.address'), $from['address']);
        $this->assertNull($from['reply_to']);
        $this->assertFalse($from['address_is_theirs']);
    }

    #[Test]
    public function the_platform_writing_on_its_own_behalf_is_still_the_platform(): void
    {
        $from = app(SenderIdentity::class)->current(null);

        $this->assertSame(config('mail.from.name'), $from['name']);
        $this->assertSame(config('mail.from.address'), $from['address']);
    }

    #[Test]
    public function a_name_takes_effect_at_once_and_an_address_does_not(): void
    {
        $this->flush();

        $tenant = $this->makeTenant('Northgate Theatre');

        app(SenderIdentity::class)->propose($tenant, 'Northgate Box Office', 'boxoffice@northgate.test');

        $from = app(SenderIdentity::class)->current($tenant->fresh());

        $this->assertSame('Northgate Box Office', $from['name']);
        // Not until somebody has read the code sent to it.
        $this->assertNull($from['reply_to']);

        $this->assertCount(1, $this->sent());
    }

    #[Test]
    public function typing_the_code_makes_replies_reach_the_venue(): void
    {
        $this->flush();

        $tenant = $this->makeTenant('Northgate Theatre');

        app(SenderIdentity::class)->propose($tenant, 'Northgate', 'boxoffice@northgate.test');

        $code = $this->codeFrom($tenant->fresh());

        app(SenderIdentity::class)->confirm($tenant->fresh(), $code);

        $from = app(SenderIdentity::class)->current($tenant->fresh());

        $this->assertSame('boxoffice@northgate.test', $from['reply_to']);
        // And still ours, because the operator has not said this domain may be sent as.
        $this->assertSame(config('mail.from.address'), $from['address']);
        $this->assertFalse($from['address_is_theirs']);
    }

    #[Test]
    public function the_from_address_is_the_venues_only_where_the_operator_arranged_it(): void
    {
        $this->flush();

        config()->set('seatmap.messaging.sender_domains', 'northgate.test, other.example');

        $tenant = $this->makeTenant('Northgate Theatre');

        app(SenderIdentity::class)->propose($tenant, 'Northgate', 'boxoffice@northgate.test');
        app(SenderIdentity::class)->confirm($tenant->fresh(), $this->codeFrom($tenant->fresh()));

        $from = app(SenderIdentity::class)->current($tenant->fresh());

        $this->assertSame('boxoffice@northgate.test', $from['address']);
        $this->assertTrue($from['address_is_theirs']);
        // No point repeating it: a reply to the From already goes there.
        $this->assertNull($from['reply_to']);
    }

    #[Test]
    public function a_subdomain_of_an_authorised_domain_counts_and_a_lookalike_does_not(): void
    {
        config()->set('seatmap.messaging.sender_domains', 'northgate.test');

        $this->assertTrue(SenderIdentity::operatorAllows('a@northgate.test'));
        $this->assertTrue(SenderIdentity::operatorAllows('a@mail.northgate.test'));
        // The one that matters: a domain somebody registered to look like it.
        $this->assertFalse(SenderIdentity::operatorAllows('a@notnorthgate.test'));
        $this->assertFalse(SenderIdentity::operatorAllows('a@northgate.test.evil.test'));
        $this->assertFalse(SenderIdentity::operatorAllows('nonsense'));
    }

    #[Test]
    public function a_wrong_code_is_refused_and_running_out_of_guesses_ends_it(): void
    {
        $this->flush();

        $tenant = $this->makeTenant();

        app(SenderIdentity::class)->propose($tenant, null, 'boxoffice@northgate.test');

        for ($i = 0; $i < SenderIdentity::MAX_ATTEMPTS; $i++) {
            try {
                app(SenderIdentity::class)->confirm($tenant->fresh(), '000000');
                $this->fail('A wrong code was accepted.');
            } catch (\App\Exceptions\ApiException $e) {
                $this->assertSame('code_wrong', $e->errorCode());
            }
        }

        // Six digits are only unguessable because the guesses run out.
        $this->expectException(\App\Exceptions\ApiException::class);
        app(SenderIdentity::class)->confirm($tenant->fresh(), '000000');
    }

    #[Test]
    public function changing_the_address_takes_the_old_one_out_of_the_header(): void
    {
        $this->flush();

        $tenant = $this->makeTenant();

        app(SenderIdentity::class)->propose($tenant, null, 'first@northgate.test');
        app(SenderIdentity::class)->confirm($tenant->fresh(), $this->codeFrom($tenant->fresh()));

        $this->assertSame(
            'first@northgate.test',
            app(SenderIdentity::class)->current($tenant->fresh())['reply_to']
        );

        app(SenderIdentity::class)->propose($tenant->fresh(), null, 'second@northgate.test');

        // The header must never point somewhere nobody has answered from, so it points nowhere
        // until the new address is proved too.
        $this->assertNull(app(SenderIdentity::class)->current($tenant->fresh())['reply_to']);
    }

    #[Test]
    public function an_account_can_go_back_to_the_platforms_own(): void
    {
        $this->flush();

        $tenant = $this->makeTenant('Northgate Theatre');

        app(SenderIdentity::class)->propose($tenant, 'Northgate', 'boxoffice@northgate.test');
        app(SenderIdentity::class)->confirm($tenant->fresh(), $this->codeFrom($tenant->fresh()));
        app(SenderIdentity::class)->forget($tenant->fresh());

        $from = app(SenderIdentity::class)->current($tenant->fresh());

        $this->assertNull($from['reply_to']);
        $this->assertSame('Northgate Theatre', $from['name']);
    }

    /* ------------------------------------------------------------------------ what is sent */

    #[Test]
    public function a_real_message_leaves_with_the_venues_name_on_it(): void
    {
        $this->flush();

        $tenant = $this->makeTenant('Northgate Theatre');

        app(TenantContext::class)->runAs($tenant, function () use ($tenant) {
            app(SenderIdentity::class)->propose($tenant, 'Northgate Box Office', 'boxoffice@northgate.test');
            app(SenderIdentity::class)->confirm($tenant->fresh(), $this->codeFrom($tenant->fresh()));

            app(MessageDispatcher::class)->send(
                'order.confirmed',
                'email',
                'sam@example.test',
                [],
                'en',
            );
        });

        $to = array_values(array_filter(
            $this->sent(),
            fn ($mail) => 'sam@example.test' === $mail->getTo()[0]->getAddress()
        ));

        $this->assertCount(1, $to);
        // The headers themselves, not a description of them: this is what a mail client renders.
        $this->assertSame('Northgate Box Office', $to[0]->getFrom()[0]->getName());
        $this->assertSame('boxoffice@northgate.test', $to[0]->getReplyTo()[0]->getAddress());
    }

    #[Test]
    public function the_code_itself_is_sent_from_the_platform(): void
    {
        $this->flush();

        $tenant = $this->makeTenant('Northgate Theatre');

        app(SenderIdentity::class)->propose($tenant, 'Northgate', 'boxoffice@northgate.test');

        // It is the message that decides whether an address may be used, so it cannot be sent as
        // that address — and it has to arrive.
        $sent = $this->sent();

        $this->assertCount(1, $sent);
        $this->assertSame('boxoffice@northgate.test', $sent[0]->getTo()[0]->getAddress());
        $this->assertSame(config('mail.from.address'), $sent[0]->getFrom()[0]->getAddress());
    }

    /* ------------------------------------------------------------------------------ the screen */

    #[Test]
    public function the_screen_walks_an_organiser_through_it(): void
    {
        $this->flush();

        $tenant = $this->makeTenant('Northgate Theatre');
        $user = $this->makeUser($tenant);

        $before = $this->actingAs($user)->getJson('/v1/messaging/sender')->assertOk()->json();

        $this->assertFalse($before['verified']);
        $this->assertFalse($before['awaiting_code']);
        $this->assertSame(config('mail.from.address'), $before['sends_as']['address']);

        $saved = $this->actingAs($user)->putJson('/v1/messaging/sender', [
            'name' => 'Northgate Box Office',
            'email' => 'boxoffice@northgate.test',
        ])->assertOk()->json();

        $this->assertTrue($saved['awaiting_code']);
        $this->assertSame('Northgate Box Office', $saved['sends_as']['name']);

        $done = $this->actingAs($user)->postJson('/v1/messaging/sender/verify', [
            'code' => $this->codeFrom($tenant->fresh()),
        ])->assertOk()->json();

        $this->assertTrue($done['verified']);
        $this->assertSame('boxoffice@northgate.test', $done['reply_to']);

        $cleared = $this->actingAs($user)->deleteJson('/v1/messaging/sender')->assertOk()->json();

        $this->assertFalse($cleared['verified']);
        $this->assertNull($cleared['email']);
    }

    #[Test]
    public function somebody_who_may_not_configure_the_account_cannot_change_who_it_writes_as(): void
    {
        $tenant = $this->makeTenant();

        $this->actingAs($this->makeUser($tenant, 'door'))
            ->putJson('/v1/messaging/sender', ['name' => 'Not mine to set'])
            ->assertStatus(403);
    }

    #[Test]
    public function one_organiser_cannot_read_anothers(): void
    {
        $this->flush();

        $theirs = $this->makeTenant('Theirs');
        app(SenderIdentity::class)->propose($theirs, 'Theirs', 'them@theirs.test');

        $mine = $this->makeTenant('Mine');

        $seen = $this->actingAs($this->makeUser($mine))
            ->getJson('/v1/messaging/sender')->assertOk()->json();

        $this->assertNull($seen['email']);
    }

    /**
     * Every email this test actually sent, as the transport saw it.
     *
     * The array transport rather than `Mail::fake()`, because the fake's `raw()` is a no-op — the
     * messages this system sends most of are invisible to it — and because the real headers are the
     * thing worth asserting on.
     *
     * @return list<\Symfony\Component\Mime\Email>
     */
    private function sent(): array
    {
        return array_map(
            fn ($sent) => $sent->getOriginalMessage(),
            Mail::mailer()->getSymfonyTransport()->messages()->all()
        );
    }

    private function flush(): void
    {
        Mail::mailer()->getSymfonyTransport()->flush();
    }

    /**
     * A known code, put where the random one was.
     *
     * The digits are a random number and the hash is one-way, so there is nothing to read back.
     * What these tests are about is what `confirm()` does with a code, not which code was drawn —
     * so the row is given one this file knows, after asserting a code was issued at all.
     */
    private function codeFrom(Tenant $tenant): string
    {
        $this->assertNotNull($tenant->sender_code_hash, 'No code was issued.');
        $this->assertTrue($tenant->sender_code_expires_at->isFuture());

        $tenant->forceFill(['sender_code_hash' => hash('sha256', '424242')])->save();

        return '424242';
    }
}
