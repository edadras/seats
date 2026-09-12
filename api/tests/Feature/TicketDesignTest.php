<?php

namespace Tests\Feature;

use App\Domain\Tickets\TicketDesigns;
use App\Domain\Tickets\TicketFields;
use App\Models\TicketDesign;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * The ticket a venue designed for one night.
 *
 * What is pinned here is mostly about *not printing rubbish onto a document somebody hands over at
 * a door*: a field nobody declared, a position off the edge of the page, a colour that is not a
 * colour. All of it is clamped rather than refused, because a form that rejects a field dragged two
 * pixels past the edge is a form people give up on — and the clamped answer is what they meant.
 *
 * The page geometry is the other half. A landscape design has to come out landscape, and that is
 * one sum in one place; getting it wrong prints an entire season sideways.
 */
class TicketDesignTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function a_night_with_no_design_prints_the_platforms_own_ticket(): void
    {
        ['tenant' => $tenant, 'event' => $event] = $this->makeSellableEvent();

        $this->assertNull(app(TenantContext::class)->runAs(
            $tenant,
            fn () => app(TicketDesigns::class)->for($event),
        ));
    }

    #[Test]
    public function a_field_nobody_declared_is_not_printed(): void
    {
        $fields = app(TicketDesigns::class)->fields([
            ['key' => 'event', 'x' => 10, 'y' => 10],
            ['key' => 'buyers_home_address', 'x' => 10, 'y' => 20],
            ['key' => 'ticket_token_of_the_next_person', 'x' => 10, 'y' => 30],
        ]);

        $this->assertSame(['event'], array_column($fields, 'key'));
    }

    #[Test]
    public function the_same_field_is_not_placed_twice(): void
    {
        $fields = app(TicketDesigns::class)->fields([
            ['key' => 'seat', 'x' => 10, 'y' => 10],
            ['key' => 'seat', 'x' => 50, 'y' => 50],
        ]);

        $this->assertCount(1, $fields);
        $this->assertSame(10.0, $fields[0]['x']);
    }

    /** Dragged off the page is somebody's slip, not their intention. */
    #[Test]
    public function a_position_past_the_edge_is_pulled_back_to_it(): void
    {
        $fields = app(TicketDesigns::class)->fields([
            ['key' => 'event', 'x' => 480, 'y' => -60, 'width' => 900, 'size' => 400],
        ]);

        $this->assertSame(100.0, $fields[0]['x']);
        $this->assertSame(0.0, $fields[0]['y']);
        $this->assertSame(100.0, $fields[0]['width']);
        $this->assertSame(72.0, $fields[0]['size']);
    }

    #[Test]
    public function a_colour_that_is_not_a_colour_does_not_reach_the_document(): void
    {
        $fields = app(TicketDesigns::class)->fields([
            ['key' => 'event', 'colour' => 'javascript:alert(1)'],
        ]);

        $this->assertMatchesRegularExpression('/^#[0-9a-fA-F]{6}$/', $fields[0]['colour']);
    }

    /** A landscape design has to come out landscape. One sum, in one place, and a season rides on it. */
    #[Test]
    public function the_page_comes_out_the_way_round_it_was_designed(): void
    {
        $portrait = new TicketDesign(['page_size' => 'A5', 'orientation' => 'portrait']);
        $landscape = new TicketDesign(['page_size' => 'A5', 'orientation' => 'landscape']);

        $this->assertSame([148.0, 210.0], $portrait->pageMillimetres());
        $this->assertSame([210.0, 148.0], $landscape->pageMillimetres());
    }

    #[Test]
    public function the_designed_ticket_is_a_pdf_with_a_page_for_every_seat(): void
    {
        ['tenant' => $tenant, 'event' => $event, 'seats' => $seats] = $this->makeSellableEvent();
        $site = $this->liveSite($tenant);

        $order = $this->confirmedOrder($tenant, $event, $seats->take(3));

        app(TenantContext::class)->runAs($tenant, function () use ($event, $site, $order) {
            $designs = app(TicketDesigns::class);
            $designs->save($event, [
                'page_size' => 'A5',
                'orientation' => 'landscape',
                'fields' => $designs->starter(),
            ]);

            $pdf = app(\App\Support\Pdf\TicketPdf::class)->render($site, $order->fresh('allocations'), []);

            $this->assertStringStartsWith('%PDF-', $pdf);
            // mPDF writes one `/Type /Page` per page plus the `/Pages` node above them.
            $this->assertSame(3, substr_count($pdf, '/Type /Page') - 1);
        });
    }

    #[Test]
    public function every_declared_field_can_be_read_off_a_booking(): void
    {
        ['tenant' => $tenant, 'event' => $event, 'seats' => $seats] = $this->makeSellableEvent();
        $site = $this->liveSite($tenant);
        $order = $this->confirmedOrder($tenant, $event, $seats->take(1));

        app(TenantContext::class)->runAs($tenant, function () use ($site, $order) {
            $order = $order->fresh(['allocations', 'event.venue']);

            $values = TicketFields::values(
                $site,
                $order,
                $order->allocations->first(),
                'A-TICKET-CODE',
                fn (string $token) => 'data:image/png;base64,AAAA',
            );

            // Every key the panel can offer has an answer, even if that answer is empty: a field
            // the renderer cannot resolve would print its own name.
            foreach (TicketFields::keys() as $key) {
                $this->assertArrayHasKey($key, $values, $key.' has no value');
                $this->assertIsString($values[$key]);
            }

            $this->assertNotSame('', $values['event']);
            $this->assertNotSame('', $values['seat']);
            $this->assertSame('A-TICKET-CODE', $values['code']);
        });
    }

    /* ------------------------------------------------------------------------- the screen */

    #[Test]
    public function the_organiser_saves_a_design_and_takes_it_back(): void
    {
        ['tenant' => $tenant, 'event' => $event] = $this->makeSellableEvent();
        $owner = $this->makeUser($tenant, 'owner');

        $this->asMember($owner)
            ->putJson('/v1/events/'.$event->id.'/ticket-design', [
                'background_url' => 'https://northgate.test/poster.jpg',
                'page_size' => 'A5',
                'orientation' => 'landscape',
                'fields' => [['key' => 'event', 'x' => 8, 'y' => 12, 'width' => 50, 'size' => 18]],
            ])
            ->assertOk()
            ->assertJsonPath('design.orientation', 'landscape')
            ->assertJsonPath('design.fields.0.key', 'event');

        $this->asMember($owner)
            ->getJson('/v1/events/'.$event->id.'/ticket-design')
            ->assertOk()
            ->assertJsonPath('design.background_url', 'https://northgate.test/poster.jpg');

        $this->asMember($owner)
            ->deleteJson('/v1/events/'.$event->id.'/ticket-design')
            ->assertOk()
            ->assertJsonPath('design', null);
    }

    /**
     * The preview is the real renderer on a booking that never happened.
     *
     * Never a real one: a preview that prints somebody's name and their ticket code puts a
     * credential on a screen an organiser is showing to a colleague.
     */
    #[Test]
    public function the_preview_is_a_document_and_carries_nobodys_ticket_code(): void
    {
        ['tenant' => $tenant, 'event' => $event, 'seats' => $seats] = $this->makeSellableEvent();
        $this->liveSite($tenant);

        $order = $this->confirmedOrder($tenant, $event, $seats->take(1));

        $real = app(TenantContext::class)->runAs(
            $tenant,
            fn () => $order->fresh('allocations')->allocations->first()->ticket?->token_prefix,
        );

        // With a design saved, so this goes through the renderer the feature is about. A preview
        // taken before anything is designed prints the platform's ticket and would pass whatever
        // the designed path did — which is how a specimen booking the designed renderer could not
        // read went out green for a whole afternoon.
        app(TenantContext::class)->runAs($tenant, function () use ($event) {
            $designs = app(TicketDesigns::class);
            $designs->save($event, ['fields' => $designs->starter()]);
        });

        $response = $this->asMember($this->makeUser($tenant, 'owner'))
            ->get('/v1/events/'.$event->id.'/ticket-design/preview')
            ->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', $response->getContent());

        if ($real) {
            $this->assertStringNotContainsString($real, $response->getContent());
        }
    }

    /**
     * The papers, with their measurements.
     *
     * The panel draws the board at the page's own shape, and it has to get that shape from the
     * renderer rather than assume it: A4, A5 and A6 are within half a per cent of one another, so
     * an assumed ratio looks correct until the first venue that prints on letter — which is nearly
     * a centimetre squarer and would move every field on the page.
     */
    #[Test]
    public function the_papers_offered_carry_the_measurements_the_document_is_made_at(): void
    {
        ['tenant' => $tenant, 'event' => $event] = $this->makeSellableEvent();

        $body = $this->asMember($this->makeUser($tenant, 'owner'))
            ->getJson('/v1/events/'.$event->id.'/ticket-design')
            ->assertOk()
            ->json('pages');

        $this->assertSameSize(TicketDesign::PAGES, $body);

        foreach ($body as $paper) {
            $this->assertArrayHasKey($paper['name'], TicketDesign::PAGES);
            // Compared as numbers rather than identically: JSON has one number type, so 210.0
            // comes back as 210 and a strict comparison would be about the transport.
            $this->assertSame(
                TicketDesign::PAGES[$paper['name']],
                [(float) $paper['width'], (float) $paper['height']],
                $paper['name'].' is not the size the renderer uses',
            );
        }
    }

    /**
     * The same screen, before anybody has designed anything.
     *
     * The button is there from the first visit, and what it shows then is the ticket the platform
     * prints — so the preview has to work on both renderers rather than only the new one.
     */
    #[Test]
    public function a_preview_of_a_night_with_no_design_is_still_a_document(): void
    {
        ['tenant' => $tenant, 'event' => $event] = $this->makeSellableEvent();
        $this->liveSite($tenant);

        $response = $this->asMember($this->makeUser($tenant, 'owner'))
            ->get('/v1/events/'.$event->id.'/ticket-design/preview')
            ->assertOk();

        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    #[Test]
    public function designing_a_ticket_is_not_a_door_volunteers_job(): void
    {
        ['tenant' => $tenant, 'event' => $event] = $this->makeSellableEvent();

        $this->asMember($this->makeUser($tenant, 'door'))
            ->putJson('/v1/events/'.$event->id.'/ticket-design', ['fields' => []])
            ->assertForbidden();
    }

    /* --------------------------------------------------------------------------- helpers */

    private function liveSite($tenant): \App\Models\Site
    {
        return app(TenantContext::class)->runAs($tenant, function () use ($tenant) {
            $site = app(\App\Domain\Sites\SiteProvisioner::class)->create($tenant->name);

            $site->update(['status' => 'live']);

            return $site->fresh();
        });
    }

    private function confirmedOrder($tenant, $event, $seats): \App\Models\ExternalOrder
    {
        return app(TenantContext::class)->runAs($tenant, function () use ($tenant, $event, $seats) {
            $client = \App\Models\ApiClient::factory()->create(['tenant_id' => $tenant->id]);

            $order = \App\Models\ExternalOrder::create([
                'event_id' => $event->id,
                'api_client_id' => $client->id,
                'external_order_id' => 'ord_'.uniqid(),
                'currency' => $event->currency,
                'status' => 'confirmed',
                'confirmed_at' => now(),
                'buyer' => ['name' => 'Amina Rahimi', 'email' => 'amina@example.test'],
            ]);

            foreach ($seats as $seat) {
                \App\Models\Allocation::create([
                    'event_id' => $event->id,
                    'api_client_id' => $client->id,
                    // `external_order_row_id` is the relation's key; `external_order_id` is the
                    // shop's own reference string and links nothing.
                    'external_order_row_id' => $order->id,
                    'external_order_id' => $order->external_order_id,
                    'seat_map_version_id' => $event->seat_map_version_id,
                    'seat_id' => $seat->id,
                    'section_name' => 'Stalls',
                    'row_name' => 'A',
                    'seat_label' => (string) $seat->id,
                    'status' => 'active',
                    'quantity' => 1,
                    'amount' => 2500,
                    'currency' => $event->currency,
                    'allocated_at' => now(),
                ]);
            }

            return $order;
        });
    }
}
