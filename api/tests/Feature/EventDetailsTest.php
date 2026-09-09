<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * What an event says about itself: its poster, what kind of evening it is, and when it starts.
 *
 * The first two exist because a listing of names and prices is a spreadsheet, and no buyer has
 * ever been sold a ticket by a spreadsheet. The third is here because a time somebody types is a
 * wall clock — "Saturday, nine" — and reading it as an instant on the server's clock sells a
 * midnight show to everybody east of Greenwich.
 */
class EventDetailsTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function an_event_carries_a_poster_and_a_kind(): void
    {
        $fixture = $this->makeSellableEvent();
        $admin = $this->makeUser($fixture['tenant'], 'admin');

        $response = $this->actingAs($admin)->patchJson("/v1/events/{$fixture['event']->id}", [
            'image_url' => 'https://cdn.example.test/posters/opening-night.jpg',
            'category' => 'Concert',
        ])->assertOk();

        $this->assertSame('https://cdn.example.test/posters/opening-night.jpg', $response->json('image_url'));
        $this->assertSame('Concert', $response->json('category'));
    }

    #[Test]
    public function a_poster_that_is_not_a_web_address_is_refused(): void
    {
        $fixture = $this->makeSellableEvent();
        $admin = $this->makeUser($fixture['tenant'], 'admin');

        // `url` on its own accepts javascript: and data:, and this value is rendered into a page
        // we serve on the organiser's own domain. That is stored XSS, not a broken picture.
        foreach (['javascript:alert(1)', 'data:text/html,<script>alert(1)</script>'] as $hostile) {
            $this->actingAs($admin)
                ->patchJson("/v1/events/{$fixture['event']->id}", ['image_url' => $hostile])
                ->assertStatus(422);
        }

        $this->assertNull($fixture['event']->fresh()->image_url);
    }

    #[Test]
    public function a_time_with_no_offset_is_read_at_the_venue_and_not_on_the_server(): void
    {
        $fixture = $this->makeSellableEvent();
        $admin = $this->makeUser($fixture['tenant'], 'admin');

        $this->actingAs($admin)->patchJson("/v1/events/{$fixture['event']->id}", [
            'timezone' => 'Europe/Istanbul',
            'starts_at' => '2027-03-14T21:00',
        ])->assertOk();

        $event = app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => Event::find($fixture['event']->id)
        );

        // Istanbul is UTC+3 all year, so nine in the evening there is six o'clock UTC.
        $this->assertSame('2027-03-14T18:00:00+00:00', $event->starts_at->utc()->toIso8601String());
        // And it reads back as the hour that was typed, which is the whole point.
        $this->assertSame('21:00', $event->starts_at->setTimezone('Europe/Istanbul')->format('H:i'));
    }

    #[Test]
    public function a_time_that_states_its_own_offset_is_believed(): void
    {
        $fixture = $this->makeSellableEvent();
        $admin = $this->makeUser($fixture['tenant'], 'admin');

        // A caller that has already said which instant it means is not second-guessed — that would
        // be the same bug pointed the other way.
        $this->actingAs($admin)->patchJson("/v1/events/{$fixture['event']->id}", [
            'timezone' => 'Europe/Istanbul',
            'starts_at' => '2027-03-14T21:00:00Z',
        ])->assertOk();

        $event = app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => Event::find($fixture['event']->id)
        );

        $this->assertSame('2027-03-14T21:00:00+00:00', $event->starts_at->utc()->toIso8601String());
    }
}
