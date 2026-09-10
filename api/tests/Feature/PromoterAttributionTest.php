<?php

namespace Tests\Feature;

use App\Domain\Attribution\Attribution;
use App\Domain\Inventory\HoldService;
use App\Domain\Orders\OrderService;
use App\Models\ExternalOrder;
use App\Models\Promoter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * Where a sale came from, and who is owed for it.
 *
 * Two claims. **What was clicked is a fact about that afternoon**: the promoter's name and their
 * percentage are copied onto the booking, so renaming somebody or agreeing a new rate next season
 * cannot rewrite what was owed for this one. And **what is owed follows the tickets**: it is worked
 * out from the bookings on every read, so a refund takes the commission back without anything
 * having to remember to.
 */
class PromoterAttributionTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    private function promoter(array $overrides = []): Promoter
    {
        return Promoter::create(array_merge([
            'name' => 'Maria Ruiz',
            'code' => 'maria',
            'commission_rate' => 1000, // ten per cent
            'active' => true,
        ], $overrides));
    }

    private function landing(array $extra = []): array
    {
        return array_merge(['code' => 'maria', 'at' => now()->toIso8601String()], $extra);
    }

    /** A confirmed booking for two seats, stamped with whatever landing is given. */
    private int $taken = 0;

    private function booking(array $night, ?array $landing, int $seats = 2): ExternalOrder
    {
        // Fresh chairs each time: two bookings in one test are two different pairs of seats, and
        // asking for the same ones twice is a refusal about inventory rather than about promoters.
        $ids = $night['seats']->slice($this->taken, $seats)->pluck('id')->all();
        $this->taken += $seats;
        $hold = app(HoldService::class)->create($night['event'], $ids, 'session-'.uniqid());
        $client = $this->makeApiClient($night['tenant'])['client'];
        $reference = 'ORD-'.strtoupper(uniqid());

        [$order] = app(OrderService::class)->register($client, $reference, $hold->token, [
            'name' => 'Sam Buyer', 'email' => 'sam@example.test',
        ]);

        app(Attribution::class)->stamp($order, $landing);
        app(OrderService::class)->confirm($order->fresh());

        return $order->fresh();
    }

    #[Test]
    public function a_link_is_read_off_the_request_and_a_search_term_is_not_kept(): void
    {
        $request = Request::create('/events/evt_x?p=maria&utm_source=instagram&utm_campaign=spring', 'GET');
        $request->headers->set('referer', 'https://www.instagram.com/p/abc?query=who+is+playing');

        $landing = app(Attribution::class)->fromRequest($request);

        $this->assertSame('maria', $landing['code']);
        $this->assertSame('instagram', $landing['utm_source']);
        $this->assertSame('spring', $landing['utm_campaign']);
        // The hostname, not the address: a referring page can carry what somebody typed into a
        // search box, and that is not ours to keep.
        $this->assertSame('www.instagram.com', $landing['referrer']);
        $this->assertStringNotContainsString('who+is+playing', json_encode($landing));
    }

    #[Test]
    public function a_request_with_nothing_on_it_is_not_a_landing(): void
    {
        $this->assertNull(app(Attribution::class)->fromRequest(Request::create('/events/evt_x', 'GET')));
    }

    #[Test]
    public function a_booking_carries_who_sold_it_and_what_they_were_owed_on_the_day(): void
    {
        $night = $this->makeSellableEvent(rows: 2, perRow: 4, amount: 5000);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $promoter = $this->promoter();
            $order = $this->booking($night, $this->landing(['utm_source' => 'instagram']));

            $this->assertSame($promoter->id, $order->promoter_id);
            $this->assertSame('Maria Ruiz', $order->attribution['promoter']);
            $this->assertSame(1000, $order->attribution['commission_rate']);
            $this->assertSame('instagram', $order->attribution['utm_source']);

            // Ten per cent of two seats at fifty euros.
            $this->assertSame(1000, app(Attribution::class)->commissionOn($order));
        });
    }

    #[Test]
    public function renaming_a_promoter_or_changing_their_rate_does_not_rewrite_last_month(): void
    {
        $night = $this->makeSellableEvent(rows: 2, perRow: 4, amount: 5000);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $promoter = $this->promoter();
            $order = $this->booking($night, $this->landing());

            $promoter->update(['name' => 'Maria Ruiz Agency', 'commission_rate' => 2500]);

            $order->refresh();

            $this->assertSame('Maria Ruiz', $order->attribution['promoter']);
            $this->assertSame(1000, app(Attribution::class)->commissionOn($order), 'the rate on the day');
        });
    }

    #[Test]
    public function a_refund_takes_the_commission_back_with_it(): void
    {
        $night = $this->makeSellableEvent(rows: 2, perRow: 4, amount: 5000);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $this->promoter();
            $order = $this->booking($night, $this->landing());

            $this->assertSame(1000, app(Attribution::class)->commissionOn($order));

            app(OrderService::class)->refund($order->fresh());

            // Nothing was swept and no column was rewritten: commission is a percentage of the
            // tickets that are still live, so it fell out on its own.
            $this->assertSame(0, app(Attribution::class)->commissionOn($order->fresh()));
        });
    }

    #[Test]
    public function a_link_older_than_the_window_has_stopped_selling(): void
    {
        $night = $this->makeSellableEvent(rows: 2, perRow: 4);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $this->promoter();

            $stale = $this->landing(['at' => now()->subDays(config('seatmap.attribution.window_days') + 1)->toIso8601String()]);

            $this->assertFalse(app(Attribution::class)->stillCounts($stale));

            $order = $this->booking($night, $stale);

            $this->assertNull($order->promoter_id);
            $this->assertNull($order->attribution);
        });
    }

    #[Test]
    public function a_code_that_names_nobody_is_still_worth_remembering(): void
    {
        $night = $this->makeSellableEvent(rows: 2, perRow: 4);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            // No promoter row: a campaign, not a person. The booking keeps where it came from and
            // owes nobody anything, which is exactly the distinction the two halves make.
            $order = $this->booking($night, $this->landing(['code' => 'poster-run', 'utm_source' => 'poster']));

            $this->assertNull($order->promoter_id);
            $this->assertSame('poster', $order->attribution['utm_source']);
            $this->assertSame(0, app(Attribution::class)->commissionOn($order));
        });
    }

    #[Test]
    public function a_deactivated_promoter_stops_earning_and_keeps_what_they_earned(): void
    {
        $night = $this->makeSellableEvent(rows: 2, perRow: 4, amount: 5000);

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $promoter = $this->promoter();
            $earned = $this->booking($night, $this->landing());

            $promoter->update(['active' => false]);

            $later = $this->booking($night, $this->landing());

            $this->assertSame($promoter->id, $earned->promoter_id);
            $this->assertNull($later->promoter_id, 'a switched-off link sells for nobody');
            $this->assertSame(1000, app(Attribution::class)->commissionOn($earned));
        });
    }

    #[Test]
    public function the_management_api_lists_them_and_adds_up_what_is_owed(): void
    {
        $night = $this->makeSellableEvent(rows: 2, perRow: 4, amount: 5000);
        $user = $this->makeUser($night['tenant']);
        $token = $user->createToken('test')->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$token];

        $created = $this->withHeaders($headers)->postJson('/v1/promoters', [
            'name' => 'Maria Ruiz', 'code' => 'maria', 'commission_rate' => 1000,
        ]);

        $created->assertCreated()
            ->assertJsonPath('commission_percent', 10)
            ->assertJsonPath('link_query', 'p=maria');

        app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $this->booking($night, $this->landing());
        });

        $this->withHeaders($headers)->getJson('/v1/promoters/performance')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Maria Ruiz')
            ->assertJsonPath('data.0.tickets', 2)
            ->assertJsonPath('data.0.gross', 10000)
            ->assertJsonPath('data.0.commission', 1000);

        // A code that is already somebody's is refused rather than quietly pointed elsewhere.
        $this->withHeaders($headers)->postJson('/v1/promoters', [
            'name' => 'Someone else', 'code' => 'maria',
        ])->assertStatus(422);
    }

    #[Test]
    public function a_promoter_who_has_sold_something_is_switched_off_rather_than_deleted(): void
    {
        $night = $this->makeSellableEvent(rows: 2, perRow: 4, amount: 5000);
        $user = $this->makeUser($night['tenant']);
        $headers = ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];

        $promoter = app(TenantContext::class)->runAs($night['tenant'], function () use ($night) {
            $promoter = $this->promoter();
            $this->booking($night, $this->landing());

            return $promoter;
        });

        $this->withHeaders($headers)->deleteJson("/v1/promoters/{$promoter->id}")
            ->assertOk()
            ->assertJsonPath('active', false);

        // Because the bookings still point at them, and a link that starts working again because
        // somebody recreated the code is worse than a promoter who is switched off.
        $this->assertNotNull(Promoter::withoutGlobalScopes()->find($promoter->id));
    }
}
