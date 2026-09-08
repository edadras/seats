<?php

namespace Tests\Feature;

use App\Domain\SeatMaps\SeatMapPublisher;
use App\Models\Seat;
use App\Models\SeatMapVersion;
use App\Models\SeatPlacement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ActsAsStorefront;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * Republishing a map must never disturb what has already been sold.
 *
 * This is the acceptance criterion that says changing a published map cannot corrupt earlier
 * orders, and it is the reason seats hang off the map rather than off a version (ADR-0002).
 */
class SeatMapVersioningTest extends TestCase
{
    use ActsAsStorefront, BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function republishing_keeps_every_seat_id(): void
    {
        $ctx = $this->makeSellableEvent(rows: 2, perRow: 3);

        $before = $this->asTenant($ctx['tenant'], fn () => Seat::orderBy('key')->pluck('id', 'key')->all());

        // Publish again with a row added and an existing row renamed.
        $this->asTenant($ctx['tenant'], function () use ($ctx) {
            $geometry = $this->geometry(3, 3);
            // Rename a row: its label changes, its key — and therefore every seat under it —
            // must not.
            $geometry['floors'][0]['objects'][0]['objects'][0]['labeling']['label'] = 'Front';

            $draft = SeatMapVersion::create([
                'seat_map_id' => $ctx['map']->id,
                'version' => 2,
                'status' => 'draft',
                'geometry' => $geometry,
            ]);

            app(SeatMapPublisher::class)->publish($ctx['map'], $draft);
        });

        $after = $this->asTenant($ctx['tenant'], fn () => Seat::orderBy('key')->pluck('id', 'key')->all());

        foreach ($before as $key => $id) {
            $this->assertSame($id, $after[$key] ?? null, "Seat {$key} was given a new id by republishing.");
        }

        $this->assertCount(9, $after, 'The new row should have added three seats.');
    }

    #[Test]
    public function a_sale_made_against_an_old_version_survives_a_republish(): void
    {
        $ctx = $this->sellableOrder('wc_6001');
        $this->storefront('POST', '/v1/integrations/woocommerce/orders/wc_6001/confirm')->assertOk();

        $originalVersion = $ctx['event']->seat_map_version_id;

        $this->asTenant($ctx['tenant'], function () use ($ctx) {
            $draft = SeatMapVersion::create([
                'seat_map_id' => $ctx['map']->id,
                'version' => 2,
                'status' => 'draft',
                'geometry' => $this->geometry(4, 5),
            ]);

            app(SeatMapPublisher::class)->publish($ctx['map'], $draft);
        });

        // The event keeps selling against the version it was published with, so a mid-season map
        // edit cannot silently move the seats a buyer already paid for.
        $this->assertSame($originalVersion, $ctx['event']->fresh()->seat_map_version_id);

        $order = $this->storefront('GET', '/v1/integrations/woocommerce/orders/wc_6001')->assertOk();

        $this->assertSame('confirmed', $order->json('status'));
        $this->assertCount(2, $order->json('allocations'));
        $this->assertSame('A', $order->json('allocations.0.row'));

        // And the sold seats still read as sold.
        $states = collect($this->getJson("/v1/embed/events/{$ctx['event']->public_id}/availability")->json('seats'))
            ->keyBy('seat_id');

        $this->assertSame('allocated', $states[$ctx['seats'][0]->id]['state']);
    }

    #[Test]
    public function a_seat_dropped_from_a_new_version_still_resolves_for_its_old_order(): void
    {
        $ctx = $this->sellableOrder('wc_6002');
        $this->storefront('POST', '/v1/integrations/woocommerce/orders/wc_6002/confirm')->assertOk();

        $soldSeatId = $ctx['seats'][0]->id;

        // Republish a map that no longer contains row A at all.
        $this->asTenant($ctx['tenant'], function () use ($ctx) {
            $geometry = $this->geometry(3, 5);
            array_shift($geometry['floors'][0]['objects'][0]['objects']);

            $draft = SeatMapVersion::create([
                'seat_map_id' => $ctx['map']->id,
                'version' => 2,
                'status' => 'draft',
                'geometry' => $geometry,
            ]);

            app(SeatMapPublisher::class)->publish($ctx['map'], $draft);
        });

        $this->asTenant($ctx['tenant'], function () use ($soldSeatId, $ctx) {
            // The seat row is still there — dropping a seat from a layout must not delete the
            // chair that somebody's ticket refers to.
            $this->assertNotNull(Seat::find($soldSeatId));

            $newVersion = $ctx['map']->fresh()->published_version_id;

            // It simply has no placement in the new version.
            $this->assertFalse(
                SeatPlacement::where('seat_map_version_id', $newVersion)->where('seat_id', $soldSeatId)->exists()
            );
        });

        // The order still reads correctly, with the seat named as it was sold.
        $order = $this->storefront('GET', '/v1/integrations/woocommerce/orders/wc_6002')->assertOk();
        $this->assertSame('1', $order->json('allocations.0.label'));
        $this->assertSame('A', $order->json('allocations.0.row'));
    }

    #[Test]
    public function a_published_version_cannot_be_edited(): void
    {
        $ctx = $this->makeSellableEvent();

        $this->asTenant($ctx['tenant'], function () use ($ctx) {
            $published = SeatMapVersion::find($ctx['map']->published_version_id);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/published and immutable/');

            $published->update(['geometry' => $this->geometry(1, 1)]);
        });
    }

    #[Test]
    public function saving_a_draft_never_touches_the_published_version(): void
    {
        $ctx = $this->makeSellableEvent();
        $user = $this->makeUser($ctx['tenant']);

        $token = $this->postJson('/v1/auth/login', [
            'email' => $user->email, 'password' => 'password',
        ])->assertOk()->json('token');

        $publishedId = $ctx['map']->published_version_id;

        $response = $this->withToken($token)->postJson("/v1/seat-maps/{$ctx['map']->id}/versions", [
            'geometry' => $this->geometry(6, 6),
        ])->assertCreated();

        $this->assertNotSame($publishedId, $response->json('id'), 'A draft must be a new version.');
        $this->assertSame('draft', $response->json('status'));

        // The event is still selling the old version until someone publishes.
        $this->assertSame($publishedId, $ctx['map']->fresh()->published_version_id);
        $this->assertSame(15, $this->asTenant(
            $ctx['tenant'],
            fn () => SeatMapVersion::find($publishedId)->seat_count
        ));
    }

    #[Test]
    public function a_draft_with_errors_is_saved_but_refused_at_publish(): void
    {
        // Saving must stay permissive — an organiser mid-edit has a broken map by definition — while
        // publishing is where the map has to be correct.
        $ctx = $this->makeSellableEvent();
        $user = $this->makeUser($ctx['tenant']);

        $token = $this->postJson('/v1/auth/login', [
            'email' => $user->email, 'password' => 'password',
        ])->assertOk()->json('token');

        $broken = $this->geometry(2, 2);
        // Drag a row clean off the canvas.
        $broken['floors'][0]['objects'][0]['objects'][0]['x'] = 99999;

        $saved = $this->withToken($token)
            ->postJson("/v1/seat-maps/{$ctx['map']->id}/versions", ['geometry' => $broken])
            ->assertCreated();

        $this->assertFalse($saved->json('validation.valid'));
        $this->assertContains('seat_off_canvas', array_column($saved->json('validation.errors'), 'code'));

        $this->withToken($token)->postJson("/v1/seat-maps/{$ctx['map']->id}/publish")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_geometry');
    }
}
