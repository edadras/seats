<?php

namespace Tests\Feature;

use App\Domain\Events\PickerEvent;
use App\Domain\SeatMaps\SeatViews;
use App\Models\SeatMapVersion;
use App\Models\SeatView;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * What a buyer would see from the seat they are about to buy.
 *
 * A chart says where a seat is and a price says what it costs. The question somebody choosing
 * between the stalls and the balcony is actually asking — what does the stage look like from there
 * — had no answer at all on this platform, and it is the one that decides the sale.
 *
 * Three claims carry it. **A photograph belongs to the room, not to the drawing of it**: it hangs
 * off the map rather than off a version, so republishing a chart neither loses the pictures nor is
 * required to change one. **An address is checked before it is stored**, because it ends up in an
 * `img src` on a page this platform serves. And **the same picture reaches every host** — the
 * hosted site, the embed on somebody else's website and the box office window all boot the picker
 * from the same event payload.
 */
class SeatViewTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function every_section_of_the_chart_is_offered_a_picture(): void
    {
        $fixture = $this->makeSellableEvent();

        $rows = app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => app(SeatViews::class)->forMap($fixture['map'])
        );

        $this->assertCount(1, $rows);
        $this->assertSame('stalls', $rows[0]['section_key']);
        // The name the designer typed, which is what the picker paints on the block.
        $this->assertSame('Stalls', $rows[0]['name']);
        $this->assertNull($rows[0]['url']);
        $this->assertSame('', $rows[0]['caption']);
    }

    #[Test]
    public function a_picture_is_saved_against_the_section_and_read_back(): void
    {
        $fixture = $this->makeSellableEvent();

        $rows = app(TenantContext::class)->runAs($fixture['tenant'], fn () => app(SeatViews::class)->save(
            $fixture['map'],
            [['section_key' => 'stalls', 'url' => 'https://cdn.example/stalls.jpg', 'caption' => 'Row F, centre']],
        ));

        $this->assertSame('https://cdn.example/stalls.jpg', $rows[0]['url']);
        $this->assertSame('Row F, centre', $rows[0]['caption']);
        $this->assertDatabaseHas('seat_views', [
            'seat_map_id' => $fixture['map']->id,
            'section_key' => 'stalls',
            'tenant_id' => $fixture['tenant']->id,
        ]);
    }

    #[Test]
    public function clearing_the_address_takes_the_picture_away(): void
    {
        $fixture = $this->makeSellableEvent();

        app(TenantContext::class)->runAs($fixture['tenant'], function () use ($fixture) {
            $views = app(SeatViews::class);

            $views->save($fixture['map'], [
                ['section_key' => 'stalls', 'url' => 'https://cdn.example/stalls.jpg'],
            ]);

            $this->assertSame(1, SeatView::where('seat_map_id', $fixture['map']->id)->count());

            // Not an empty string in the column: "no picture" is the absence of one, and storing
            // the absence would put an empty frame in a buyer's way.
            $rows = $views->save($fixture['map'], [['section_key' => 'stalls', 'url' => '']]);

            $this->assertSame(0, SeatView::where('seat_map_id', $fixture['map']->id)->count());
            $this->assertNull($rows[0]['url']);
        });
    }

    #[Test]
    public function an_address_that_is_not_a_web_address_is_refused(): void
    {
        $fixture = $this->makeSellableEvent();

        app(TenantContext::class)->runAs($fixture['tenant'], function () use ($fixture) {
            foreach (['javascript:alert(1)', 'data:image/svg+xml;base64,AAAA', 'not a url'] as $bad) {
                app(SeatViews::class)->save($fixture['map'], [
                    ['section_key' => 'stalls', 'url' => $bad],
                ]);

                $this->assertSame(
                    0,
                    SeatView::where('seat_map_id', $fixture['map']->id)->count(),
                    $bad.' was stored',
                );
            }
        });
    }

    #[Test]
    public function a_section_this_chart_does_not_have_is_ignored(): void
    {
        $fixture = $this->makeSellableEvent();

        $rows = app(TenantContext::class)->runAs($fixture['tenant'], fn () => app(SeatViews::class)->save(
            $fixture['map'],
            [
                ['section_key' => 'stalls', 'url' => 'https://cdn.example/stalls.jpg'],
                ['section_key' => 'balcony', 'url' => 'https://cdn.example/nowhere.jpg'],
            ],
        ));

        $this->assertCount(1, $rows);
        $this->assertSame(0, SeatView::where('section_key', 'balcony')->count());
    }

    #[Test]
    public function a_caption_longer_than_the_column_is_cut_rather_than_refused(): void
    {
        $fixture = $this->makeSellableEvent();

        app(TenantContext::class)->runAs($fixture['tenant'], function () use ($fixture) {
            app(SeatViews::class)->save($fixture['map'], [[
                'section_key' => 'stalls',
                'url' => 'https://cdn.example/stalls.jpg',
                'caption' => str_repeat('a', 400),
            ]]);

            $this->assertSame(200, mb_strlen(SeatView::first()->caption));
        });
    }

    #[Test]
    public function the_picker_is_booted_with_the_picture_for_each_section(): void
    {
        $fixture = $this->makeSellableEvent();

        $payload = app(TenantContext::class)->runAs($fixture['tenant'], function () use ($fixture) {
            app(SeatViews::class)->save($fixture['map'], [[
                'section_key' => 'stalls',
                'url' => 'https://cdn.example/stalls.jpg',
                'caption' => 'Row F, centre',
            ]]);

            return app(PickerEvent::class)->forEvent($fixture['event']);
        });

        $this->assertSame([
            'stalls' => ['url' => 'https://cdn.example/stalls.jpg', 'caption' => 'Row F, centre'],
        ], $payload['views']);
    }

    #[Test]
    public function the_embed_carries_the_picture_to_somebody_elses_website(): void
    {
        $fixture = $this->makeSellableEvent();

        app(TenantContext::class)->runAs($fixture['tenant'], fn () => app(SeatViews::class)->save(
            $fixture['map'],
            [['section_key' => 'stalls', 'url' => 'https://cdn.example/stalls.jpg']],
        ));

        $this->getJson('/v1/embed/events/'.$fixture['event']->public_id)
            ->assertOk()
            ->assertJsonPath('views.stalls.url', 'https://cdn.example/stalls.jpg');
    }

    #[Test]
    public function a_picture_survives_republishing_the_chart(): void
    {
        $fixture = $this->makeSellableEvent();

        app(TenantContext::class)->runAs($fixture['tenant'], function () use ($fixture) {
            app(SeatViews::class)->save($fixture['map'], [
                ['section_key' => 'stalls', 'url' => 'https://cdn.example/stalls.jpg'],
            ]);

            $next = SeatMapVersion::create([
                'seat_map_id' => $fixture['map']->id,
                'version' => 2,
                'status' => 'draft',
                'geometry' => $this->geometry(4, 6),
            ]);

            app(\App\Domain\SeatMaps\SeatMapPublisher::class)->publish($fixture['map'], $next);

            // The room did not change; the drawing of it did.
            $this->assertSame(
                'https://cdn.example/stalls.jpg',
                app(SeatViews::class)->forBoot($next->fresh())['stalls']['url'],
            );
        });
    }

    #[Test]
    public function the_screen_lists_the_sections_and_saves_what_is_typed(): void
    {
        $fixture = $this->makeSellableEvent();
        $user = $this->makeUser($fixture['tenant']);

        $this->asMember($user)
            ->getJson('/v1/seat-maps/'.$fixture['map']->id.'/views')
            ->assertOk()
            ->assertJsonPath('data.0.section_key', 'stalls');

        $this->asMember($user)
            ->putJson('/v1/seat-maps/'.$fixture['map']->id.'/views', [
                'views' => [
                    ['section_key' => 'stalls', 'url' => 'https://cdn.example/stalls.jpg', 'caption' => 'Row F'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.0.url', 'https://cdn.example/stalls.jpg');

        $this->assertDatabaseHas('audit_logs', ['action' => 'seat_map.views_saved']);
    }

    #[Test]
    public function the_endpoint_refuses_an_address_a_browser_would_execute(): void
    {
        $fixture = $this->makeSellableEvent();
        $user = $this->makeUser($fixture['tenant']);

        $this->asMember($user)
            ->putJson('/v1/seat-maps/'.$fixture['map']->id.'/views', [
                'views' => [['section_key' => 'stalls', 'url' => 'javascript:alert(1)']],
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function somebody_who_may_not_edit_charts_may_not_attach_pictures(): void
    {
        $fixture = $this->makeSellableEvent();
        $clerk = $this->makeUser($fixture['tenant'], 'box_office');

        $this->asMember($clerk)
            ->putJson('/v1/seat-maps/'.$fixture['map']->id.'/views', [
                'views' => [['section_key' => 'stalls', 'url' => 'https://cdn.example/stalls.jpg']],
            ])
            ->assertStatus(403);
    }

    #[Test]
    public function one_accounts_chart_is_not_another_accounts_to_read(): void
    {
        $mine = $this->makeSellableEvent();
        $theirs = $this->makeSellableEvent($this->makeTenant('Someone Else'));

        $this->asMember($this->makeUser($mine['tenant']))
            ->getJson('/v1/seat-maps/'.$theirs['map']->id.'/views')
            ->assertStatus(404);
    }
}
