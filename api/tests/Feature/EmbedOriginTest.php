<?php

namespace Tests\Feature;

use App\Domain\Embed\EmbedOrigins;
use App\Models\EmbedOrigin;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * The hall opens where the venue said it may, and nowhere else.
 *
 * The embed carries no key on purpose — three lines of HTML for somebody with a page and no
 * toolchain — and that is exactly what made it copyable: view source on a venue's booking page,
 * paste the two tags on your own site, and their hall opened there, holding seats out of their real
 * inventory.
 *
 * What is pinned here is the shape of the answer rather than a promise it cannot keep. `Origin` is
 * a fact a browser states and will not let a page lie about; it is not proof about a person, and a
 * server-side proxy can send whatever it likes. That is acceptable *here* and nowhere else on this
 * platform: behind these endpoints is a public programme and a chart the venue already shows the
 * world.
 */
class EmbedOriginTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function a_website_the_venue_named_may_draw_the_hall(): void
    {
        ['tenant' => $tenant, 'event' => $event] = $this->makeSellableEvent();
        $this->allow($tenant, 'northgate.test');

        $this->withHeader('Origin', 'https://northgate.test')
            ->getJson('/v1/embed/events/'.$event->public_id)
            ->assertOk();
    }

    #[Test]
    public function a_website_the_venue_never_heard_of_may_not(): void
    {
        ['tenant' => $tenant, 'event' => $event] = $this->makeSellableEvent();
        $this->allow($tenant, 'northgate.test');

        $this->withHeader('Origin', 'https://thief.example')
            ->getJson('/v1/embed/events/'.$event->public_id)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'embed_origin_not_allowed');
    }

    /**
     * Every endpoint behind the prefix, not merely the first one.
     *
     * The chart is the thing worth taking, and it is a different action from the event. A guard on
     * one of seven is a guard on none.
     */
    #[Test]
    public function every_embed_endpoint_is_behind_the_same_gate(): void
    {
        ['tenant' => $tenant, 'event' => $event, 'seats' => $seats] = $this->makeSellableEvent();
        $this->allow($tenant, 'northgate.test');

        foreach (['', '/seat-map', '/availability'] as $path) {
            $this->withHeader('Origin', 'https://thief.example')
                ->getJson('/v1/embed/events/'.$event->public_id.$path)
                ->assertForbidden();
        }

        $this->withHeader('Origin', 'https://thief.example')
            ->postJson('/v1/embed/events/'.$event->public_id.'/holds', [
                'seat_ids' => [$seats->first()->id],
                'session_id' => 'thief',
            ])
            ->assertForbidden();
    }

    /**
     * A hold is addressed by its token rather than by an event, so the gate has to find the tenant
     * the other way round — and if it did not, extending and releasing would be the way past it.
     */
    #[Test]
    public function a_hold_is_guarded_by_the_venue_that_owns_it(): void
    {
        ['tenant' => $tenant, 'event' => $event, 'seats' => $seats] = $this->makeSellableEvent();
        $this->allow($tenant, 'northgate.test');

        $token = $this->withHeader('Origin', 'https://northgate.test')
            ->postJson('/v1/embed/events/'.$event->public_id.'/holds', [
                'seat_ids' => [$seats->first()->id],
                'session_id' => 'a-buyer',
            ])
            ->assertCreated()
            ->json('hold_token');

        $this->withHeader('Origin', 'https://thief.example')
            ->patchJson('/v1/embed/holds/'.$token.'/extend')
            ->assertForbidden();

        $this->withHeader('Origin', 'https://thief.example')
            ->deleteJson('/v1/embed/holds/'.$token)
            ->assertForbidden();
    }

    /**
     * A caller that states no origin is not refused, and that is deliberate.
     *
     * The thing being stopped is a copied snippet, which runs in a browser, and a browser always
     * states its origin on a cross-origin fetch. A caller with none is a script or a proxy — and
     * anything in that position can forge a permitted origin in one line, so refusing the empty
     * case would stop only somebody who built a proxy and then could not be bothered, while
     * breaking every honest integration that is not a browser.
     *
     * Pinned as a test rather than left as a comment because it looks like a hole until the
     * reasoning is in front of you, and the next person to read it will want to close it.
     */
    #[Test]
    public function a_caller_that_states_no_origin_is_not_refused(): void
    {
        ['tenant' => $tenant, 'event' => $event] = $this->makeSellableEvent();
        $this->allow($tenant, 'northgate.test');

        $this->getJson('/v1/embed/events/'.$event->public_id)->assertOk();
    }

    /** But something that states an origin which is not an address is not believed either. */
    #[Test]
    public function an_origin_that_is_not_an_address_is_refused(): void
    {
        ['tenant' => $tenant, 'event' => $event] = $this->makeSellableEvent();
        $this->allow($tenant, 'northgate.test');

        $this->withHeader('Origin', '///')
            ->getJson('/v1/embed/events/'.$event->public_id)
            ->assertForbidden();
    }

    #[Test]
    public function an_account_that_has_named_no_website_shows_its_hall_on_none(): void
    {
        ['event' => $event] = $this->makeSellableEvent();

        $this->withHeader('Origin', 'https://anywhere.example')
            ->getJson('/v1/embed/events/'.$event->public_id)
            ->assertForbidden();
    }

    /** One venue's list is not another's. */
    #[Test]
    public function a_list_belongs_to_one_venue(): void
    {
        ['tenant' => $ours, 'event' => $event] = $this->makeSellableEvent();
        $theirs = $this->makeTenant('Somebody Else');

        $this->allow($theirs, 'thief.example');
        $this->allow($ours, 'northgate.test');

        $this->withHeader('Origin', 'https://thief.example')
            ->getJson('/v1/embed/events/'.$event->public_id)
            ->assertForbidden();
    }

    /**
     * `www` is not a different website, and a default port is not part of an origin.
     *
     * Both are ways an organiser ends up looking at a list that says the site is allowed while the
     * site is refused, which is worse than a refusal they understand.
     */
    #[Test]
    public function the_spellings_a_venue_will_actually_type_all_work(): void
    {
        ['tenant' => $tenant, 'event' => $event] = $this->makeSellableEvent();
        $this->allow($tenant, 'https://northgate.test:443/tickets?from=poster');

        foreach (['https://northgate.test', 'https://www.northgate.test'] as $origin) {
            $this->withHeader('Origin', $origin)
                ->getJson('/v1/embed/events/'.$event->public_id)
                ->assertOk();
        }
    }

    #[Test]
    public function a_port_is_kept_because_two_ports_are_two_websites(): void
    {
        ['tenant' => $tenant, 'event' => $event] = $this->makeSellableEvent();
        $this->allow($tenant, 'http://localhost:8200');

        $this->withHeader('Origin', 'http://localhost:8200')
            ->getJson('/v1/embed/events/'.$event->public_id)->assertOk();

        $this->withHeader('Origin', 'http://localhost:3000')
            ->getJson('/v1/embed/events/'.$event->public_id)->assertForbidden();
    }

    /* ------------------------------------------------------------------------- the screen */

    #[Test]
    public function the_organiser_can_name_a_website_and_take_it_back(): void
    {
        $tenant = $this->makeTenant();
        $owner = $this->makeUser($tenant, 'owner');

        $this->asMember($owner)
            ->postJson('/v1/embed-origins', ['hostname' => 'https://northgate.test/tickets', 'label' => 'Our WordPress'])
            ->assertCreated()
            ->assertJsonPath('data.0.hostname', 'northgate.test')
            ->assertJsonPath('data.0.label', 'Our WordPress');

        $id = $this->asMember($owner)->getJson('/v1/embed-origins')->json('data.0.id');

        $this->asMember($owner)
            ->deleteJson('/v1/embed-origins/'.$id)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function something_that_is_not_an_address_is_refused(): void
    {
        $tenant = $this->makeTenant();

        $this->asMember($this->makeUser($tenant, 'owner'))
            ->postJson('/v1/embed-origins', ['hostname' => '///'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'embed_origin_invalid');
    }

    #[Test]
    public function naming_a_website_is_not_a_box_office_volunteers_job(): void
    {
        $tenant = $this->makeTenant();

        $this->asMember($this->makeUser($tenant, 'box_office'))
            ->postJson('/v1/embed-origins', ['hostname' => 'thief.example'])
            ->assertForbidden();
    }

    /* --------------------------------------------------------------------------- helpers */

    private function allow(Tenant $tenant, string $hostname): void
    {
        app(TenantContext::class)->runAs($tenant, function () use ($tenant, $hostname) {
            app(EmbedOrigins::class)->add($hostname, null);
        });

        app(EmbedOrigins::class)->forget($tenant->id);
    }
}
