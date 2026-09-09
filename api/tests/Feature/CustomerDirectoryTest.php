<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ExternalOrder;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * The customer directory.
 *
 * There is no customers table: the list is worked out from the orders, so the tests that matter
 * are the ones about identity and isolation — that two orders from the same address are one
 * person however they typed it, that an order nobody can be named for is left out rather than
 * shown as an anonymous customer, and that the whole thing is behind `orders.view` and the tenant
 * scope like everything else the box office can see.
 */
class CustomerDirectoryTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function one_person_is_one_row_however_they_typed_their_address(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        $this->sell($fixture, ['name' => 'Dana Scully', 'email' => 'dana@example.test'], [0]);
        $this->sell($fixture, ['name' => 'Dana Scully', 'email' => 'DANA@Example.test'], [5, 6]);
        $this->sell($fixture, ['name' => 'Amir Rahimi', 'email' => 'amir@example.test'], [10]);

        $body = $this->actingAs($owner)->getJson('/v1/customers')->assertOk()->json();

        $this->assertCount(2, $body['data'], 'Two people, not three orders.');

        $dana = collect($body['data'])->firstWhere('email', 'dana@example.test');

        $this->assertSame(2, $dana['orders_count']);
        $this->assertSame(3, $dana['seats_count'], 'One seat on the first order, two on the second.');
        $this->assertSame([['currency' => 'EUR', 'amount' => 7500]], $dana['spend']);
        $this->assertSame(7500, $dana['spend_in_currency']);
        $this->assertSame(1, $dana['events_count']);
    }

    #[Test]
    public function an_order_with_no_address_belongs_to_nobody_and_is_counted_as_such(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        $this->sell($fixture, ['name' => 'Dana Scully', 'email' => 'dana@example.test'], [0]);
        $order = $this->sell($fixture, ['name' => 'Walk-up', 'email' => 'later@example.test'], [5]);

        // A shop that hands over an order without an address — a walk-up sale, a comped seat.
        app(TenantContext::class)->runAs($fixture['tenant'], fn () => ExternalOrder::whereKey($order)
            ->update(['buyer' => json_encode(['name' => 'Walk-up'])]));

        $body = $this->actingAs($owner)->getJson('/v1/customers')->assertOk()->json();

        $this->assertCount(1, $body['data']);
        $this->assertSame(1, $body['meta']['without_email'], 'Left out, but admitted to.');
    }

    #[Test]
    public function the_profile_shows_what_that_person_bought(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        $this->sell($fixture, ['name' => 'Lotte Weber', 'email' => 'lotte@example.test'], [0, 1]);

        $list = $this->actingAs($owner)->getJson('/v1/customers')->assertOk()->json();
        $id = $list['data'][0]['id'];

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $id, 'Addressed by a hash, never by the address.');
        $this->assertStringNotContainsString('lotte@example.test', $id);

        $person = $this->actingAs($owner)->getJson('/v1/customers/'.$id)->assertOk()->json();

        $this->assertSame('lotte@example.test', $person['email']);
        $this->assertSame('Lotte Weber', $person['name']);
        $this->assertCount(1, $person['orders']);
        $this->assertCount(2, $person['orders'][0]['lines']);
        $this->assertSame(5000, $person['orders'][0]['total_amount']);
        $this->assertSame('Stalls', $person['orders'][0]['lines'][0]['section']);
        $this->assertSame($fixture['event']->name, $person['orders'][0]['event']['name']);
    }

    #[Test]
    public function a_hash_that_matches_nobody_is_not_a_customer_with_no_orders(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        $this->actingAs($owner)->getJson('/v1/customers/'.str_repeat('a', 64))->assertNotFound();
        $this->actingAs($owner)->getJson('/v1/customers/not-a-hash')->assertNotFound();
    }

    #[Test]
    public function another_organisers_buyers_are_not_in_this_list(): void
    {
        $ours = $this->makeSellableEvent();
        $theirs = $this->makeSellableEvent($this->makeTenant('Rival Halls'));
        $owner = $this->makeUser($ours['tenant']);

        $this->sell($ours, ['name' => 'Ours', 'email' => 'ours@example.test'], [0]);
        $this->sell($theirs, ['name' => 'Theirs', 'email' => 'theirs@example.test'], [0]);

        $body = $this->actingAs($owner)->getJson('/v1/customers')->assertOk()->json();

        $this->assertCount(1, $body['data']);
        $this->assertSame('ours@example.test', $body['data'][0]['email']);
        $this->assertSame(0, $body['meta']['without_email']);
    }

    #[Test]
    public function the_door_cannot_read_the_buyers_and_the_box_office_can(): void
    {
        $fixture = $this->makeSellableEvent();
        $this->sell($fixture, ['name' => 'Dana Scully', 'email' => 'dana@example.test'], [0]);

        // A volunteer on the door needs to know who has arrived, not who paid and how much.
        $this->actingAs($this->makeUser($fixture['tenant'], 'door'))
            ->getJson('/v1/customers')->assertForbidden();

        $this->actingAs($this->makeUser($fixture['tenant'], 'box_office'))
            ->getJson('/v1/customers')->assertOk();
    }

    #[Test]
    public function searching_finds_a_person_by_address_and_by_order_reference(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        $this->sell($fixture, ['name' => 'Dana Scully', 'email' => 'dana@example.test'], [0]);
        $reference = $this->sellReference;
        $this->sell($fixture, ['name' => 'Amir Rahimi', 'email' => 'amir@example.test'], [5]);

        $byAddress = $this->actingAs($owner)->getJson('/v1/customers?q=DANA@')->assertOk()->json();
        $this->assertCount(1, $byAddress['data']);
        $this->assertSame('dana@example.test', $byAddress['data'][0]['email']);

        $byReference = $this->actingAs($owner)->getJson('/v1/customers?q='.$reference)->assertOk()->json();
        $this->assertCount(1, $byReference['data']);
        $this->assertSame('dana@example.test', $byReference['data'][0]['email']);
    }

    #[Test]
    public function taking_the_list_out_of_the_platform_is_written_down(): void
    {
        $fixture = $this->makeSellableEvent();
        $owner = $this->makeUser($fixture['tenant']);

        $this->sell($fixture, ['name' => 'Dana Scully', 'email' => 'dana@example.test'], [0]);

        $response = $this->actingAs($owner)->get('/v1/customers/export');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();
        $this->assertStringContainsString('dana@example.test', $csv);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'A BOM, so a spreadsheet opens it as text.');

        app(TenantContext::class)->runAs($fixture['tenant'], function () {
            $this->assertTrue(AuditLog::where('action', 'customers.exported')->exists());
        });
    }

    /* --------------------------------------------------------------------------- helpers */

    private string $sellReference = '';

    /**
     * Sell some seats to somebody, the way a shop does: hold, register, confirm.
     *
     * Returns the order's id, and leaves its reference on the test so a search can look for it.
     *
     * @param  list<int>  $seats  indexes into the fixture's seats
     */
    private function sell(array $fixture, array $buyer, array $seats): string
    {
        $event = $fixture['event'];
        $this->sellReference = 'wc_'.Str::lower(Str::random(8));

        $hold = $this->postJson("/v1/embed/events/{$event->public_id}/holds", [
            'seat_ids' => array_map(fn (int $index) => $fixture['seats'][$index]->id, $seats),
            'session_id' => 'sess_'.Str::random(8),
        ])->assertCreated()->json();

        $api = $this->apiClientFor($fixture['tenant']);

        $body = json_encode([
            'external_order_id' => $this->sellReference,
            'hold_token' => $hold['hold_token'],
        ]);

        $this->call(
            'POST', '/v1/integrations/woocommerce/orders', [], [], [],
            $this->serverHeaders($this->signedHeaders(
                $api['key_id'], $api['secret'], 'POST', '/v1/integrations/woocommerce/orders', $body
            )),
            $body,
        )->assertCreated();

        $path = '/v1/integrations/woocommerce/orders/'.$this->sellReference.'/confirm';
        $confirm = json_encode(['buyer' => $buyer]);

        $this->call(
            'POST', $path, [], [], [],
            $this->serverHeaders($this->signedHeaders($api['key_id'], $api['secret'], 'POST', $path, $confirm)),
            $confirm,
        )->assertOk();

        return app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => ExternalOrder::where('external_order_id', $this->sellReference)->firstOrFail()->id
        );
    }

    /** One storefront per tenant: issuing a second key for every sale would prove nothing. */
    private array $clients = [];

    private function apiClientFor(Tenant $tenant): array
    {
        return $this->clients[$tenant->id] ??= $this->makeApiClient($tenant);
    }
}
