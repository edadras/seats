<?php

namespace Tests\Feature;

use App\Domain\Reports\ReportRunner;
use App\Domain\Reports\SourceRegistry;
use App\Models\Report;
use App\Models\ReportPage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * The report builder (ADR-0006).
 *
 * The point of the design is what it refuses. There is no SQL box, so the tests that matter are
 * the ones where somebody sends a field name that is not declared, a sort direction that is not a
 * direction, or asks for a report whose source they may not read.
 */
class ReportBuilderTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function a_report_counts_what_actually_sold(): void
    {
        $fixture = $this->sold();
        $owner = $this->makeUser($fixture['tenant']);

        $body = $this->actingAs($owner)->postJson('/v1/reports/run', [
            'source' => 'seats_sold',
            'definition' => [
                'dimensions' => ['section'],
                'measures' => ['seats', 'revenue'],
            ],
        ])->assertOk()->json();

        $this->assertCount(1, $body['rows']);
        $this->assertSame(2, $body['rows'][0]['m0'], 'Two seats were sold.');
        $this->assertSame(5000, $body['rows'][0]['m1'], 'At 2500 each.');
        $this->assertSame('Stalls', $body['rows'][0]['d0']);
        $this->assertSame('money', $body['columns'][2]['format']);
    }

    #[Test]
    public function revenue_is_not_multiplied_by_the_seats_on_the_order(): void
    {
        $fixture = $this->sold();
        $owner = $this->makeUser($fixture['tenant']);

        $body = $this->actingAs($owner)->postJson('/v1/reports/run', [
            'source' => 'orders',
            'definition' => ['dimensions' => ['event'], 'measures' => ['orders', 'revenue']],
        ])->assertOk()->json();

        // One order of two seats. Joining the seats in would say two orders and twice the money,
        // which is the classic way a revenue report ends up quietly wrong.
        $this->assertSame(1, $body['rows'][0]['m0']);
        $this->assertSame(5000, $body['rows'][0]['m1']);
    }

    #[Test]
    public function a_field_that_was_never_declared_does_not_reach_the_database(): void
    {
        $fixture = $this->sold();
        $owner = $this->makeUser($fixture['tenant']);

        foreach ([
            ['dimensions' => ['allocations.amount) as x, (select 1'], 'measures' => ['seats']],
            ['dimensions' => ['section'], 'measures' => ['id']],
            ['dimensions' => ['section'], 'measures' => ['seats'], 'filters' => ['tenant_id' => 'x']],
        ] as $definition) {
            $this->actingAs($owner)->postJson('/v1/reports/run', [
                'source' => 'seats_sold',
                'definition' => $definition,
            ])->assertStatus(422);
        }
    }

    #[Test]
    public function the_sort_direction_is_one_of_two_words_we_wrote(): void
    {
        $fixture = $this->sold();
        $owner = $this->makeUser($fixture['tenant']);

        // Anything that is not "asc" is treated as descending rather than passed through.
        $this->actingAs($owner)->postJson('/v1/reports/run', [
            'source' => 'seats_sold',
            'definition' => [
                'dimensions' => ['section'],
                'measures' => ['seats'],
                'sort' => ['key' => 'm0', 'direction' => 'asc; drop table allocations'],
            ],
        ])->assertOk();

        $this->assertDatabaseCount('allocations', 2);
    }

    #[Test]
    public function a_saved_sort_names_the_field_rather_than_its_position(): void
    {
        $fixture = $this->sold();
        $owner = $this->makeUser($fixture['tenant']);

        // `m0` is fine for the screen to send about the report currently on it. A stored report
        // sorted by `m0` changes what it sorts by the moment somebody drags a column in front of
        // it, so the field's own name has to work too.
        $body = $this->actingAs($owner)->postJson('/v1/reports/run', [
            'source' => 'seats_sold',
            'definition' => [
                'dimensions' => ['row'],
                'measures' => ['seats', 'revenue'],
                'sort' => ['key' => 'revenue', 'direction' => 'asc'],
            ],
        ])->assertOk()->json();

        $this->assertSame(['alias' => 'm1', 'direction' => 'asc'], $body['sort']);

        // And a name nobody declared is still a refusal, not a column.
        $this->actingAs($owner)->postJson('/v1/reports/run', [
            'source' => 'seats_sold',
            'definition' => [
                'dimensions' => ['row'],
                'measures' => ['seats'],
                'sort' => ['key' => 'allocations.tenant_id'],
            ],
        ])->assertStatus(422)->assertJsonPath('error.code', 'unknown_sort');
    }

    #[Test]
    public function the_buyers_dataset_counts_people_rather_than_orders(): void
    {
        $fixture = $this->sold();
        $owner = $this->makeUser($fixture['tenant']);

        $body = $this->actingAs($owner)->postJson('/v1/reports/run', [
            'source' => 'buyers',
            'definition' => ['dimensions' => ['buyer_email'], 'measures' => ['customers', 'orders', 'revenue']],
        ])->assertOk()->json();

        $this->assertCount(1, $body['rows']);
        $this->assertSame('dana@example.test', $body['rows'][0]['d0']);
        $this->assertSame(1, $body['rows'][0]['m0'], 'One person.');
        $this->assertSame(1, $body['rows'][0]['m1'], 'One order.');
        $this->assertSame(5000, $body['rows'][0]['m2'], 'Not multiplied by the seats on it.');

        // Buyers are money, not attendance: the door may not read them.
        $this->actingAs($this->makeUser($fixture['tenant'], 'door'))->postJson('/v1/reports/run', [
            'source' => 'buyers',
            'definition' => ['dimensions' => ['buyer'], 'measures' => ['orders']],
        ])->assertForbidden();
    }

    #[Test]
    public function a_page_can_carry_a_note_as_well_as_a_report(): void
    {
        $fixture = $this->sold();
        $owner = $this->makeUser($fixture['tenant']);

        $report = $this->actingAs($owner)->postJson('/v1/reports', [
            'name' => 'Seats by section',
            'source' => 'seats_sold',
            'definition' => ['dimensions' => ['section'], 'measures' => ['seats']],
        ])->assertCreated()->json();

        $page = $this->actingAs($owner)->postJson('/v1/report-pages', ['name' => 'Monday'])
            ->assertCreated()->json();

        $this->actingAs($owner)->patchJson('/v1/report-pages/'.$page['id'], [
            'widgets' => [
                ['type' => 'note', 'title' => 'Read me', 'text' => '<b>Chase</b> the refunds.', 'width' => 'third'],
                ['type' => 'bar', 'report_id' => $report['id'], 'width' => 'half'],
                // No report and not a note: nothing to draw, so nothing is stored.
                ['type' => 'table', 'title' => 'Nothing'],
            ],
        ])->assertOk();

        $body = $this->actingAs($owner)->getJson('/v1/report-pages/'.$page['id'])->assertOk()->json();

        $this->assertCount(2, $body['widgets']);
        $this->assertSame('note', $body['widgets'][0]['type']);
        $this->assertSame('third', $body['widgets'][0]['width']);
        // Stored as text, because it is rendered inside somebody else's panel.
        $this->assertSame('Chase the refunds.', $body['widgets'][0]['text']);
        $this->assertSame('bar', $body['widgets'][1]['type']);
        $this->assertArrayHasKey('rows', $body['widgets'][1]);
    }

    #[Test]
    public function a_report_has_to_count_something(): void
    {
        $fixture = $this->sold();
        $owner = $this->makeUser($fixture['tenant']);

        $this->actingAs($owner)->postJson('/v1/reports/run', [
            'source' => 'seats_sold',
            'definition' => ['dimensions' => ['section'], 'measures' => []],
        ])->assertStatus(422)->assertJsonPath('error.code', 'report_needs_a_measure');
    }

    #[Test]
    public function money_and_attendance_are_different_permissions(): void
    {
        $fixture = $this->sold();
        $door = $this->makeUser($fixture['tenant'], 'door');

        // The door may see who came in.
        $this->actingAs($door)->postJson('/v1/reports/run', [
            'source' => 'checkins',
            'definition' => ['dimensions' => ['result'], 'measures' => ['scans']],
        ])->assertOk();

        // And may not see what the evening took, on any screen, however the report was made.
        $this->actingAs($door)->postJson('/v1/reports/run', [
            'source' => 'orders',
            'definition' => ['dimensions' => ['event'], 'measures' => ['revenue']],
        ])->assertForbidden();

        $sources = $this->actingAs($door)->getJson('/v1/reports/sources')->assertOk()->json('data');
        $keys = array_column($sources, 'key');

        $this->assertContains('checkins', $keys);
        $this->assertNotContains('orders', $keys);
    }

    #[Test]
    public function saving_a_report_cannot_widen_what_its_reader_may_see(): void
    {
        $fixture = $this->sold();
        $owner = $this->makeUser($fixture['tenant']);
        $door = $this->makeUser($fixture['tenant'], 'door');

        $report = $this->actingAs($owner)->postJson('/v1/reports', [
            'name' => 'Takings by night',
            'source' => 'orders',
            'definition' => ['dimensions' => ['event'], 'measures' => ['revenue']],
        ])->assertCreated()->json();

        $this->actingAs($door)->getJson('/v1/reports/'.$report['id'].'/run')->assertForbidden();
        $this->actingAs($door)->getJson('/v1/reports')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($owner)->getJson('/v1/reports/'.$report['id'].'/run')->assertOk();
    }

    #[Test]
    public function a_definition_that_cannot_run_is_not_stored(): void
    {
        $fixture = $this->sold();
        $owner = $this->makeUser($fixture['tenant']);

        $this->actingAs($owner)->postJson('/v1/reports', [
            'name' => 'Nonsense',
            'source' => 'orders',
            'definition' => ['dimensions' => ['nowhere'], 'measures' => ['revenue']],
        ])->assertStatus(422);

        $this->assertDatabaseCount('reports', 0);
    }

    #[Test]
    public function a_report_says_when_it_has_more_to_give(): void
    {
        $fixture = $this->sold();
        $owner = $this->makeUser($fixture['tenant']);

        $body = $this->actingAs($owner)->postJson('/v1/reports/run', [
            'source' => 'seats_sold',
            'definition' => [
                'dimensions' => ['section', 'row'],
                'measures' => ['seats'],
                'limit' => 1,
            ],
        ])->assertOk()->json();

        $this->assertCount(1, $body['rows']);
        $this->assertTrue($body['truncated'], 'There was more than one row.');
        $this->assertSame(1, $body['limit']);
    }

    #[Test]
    public function the_export_is_the_same_definition_and_the_same_runner(): void
    {
        $fixture = $this->sold();
        $owner = $this->makeUser($fixture['tenant']);

        $report = $this->actingAs($owner)->postJson('/v1/reports', [
            'name' => 'Seats by section',
            'source' => 'seats_sold',
            'definition' => ['dimensions' => ['section'], 'measures' => ['seats']],
        ])->json();

        $response = $this->actingAs($owner)->get('/v1/reports/'.$report['id'].'/export');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Stalls', $csv);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'A BOM, so a spreadsheet opens it as UTF-8.');
    }

    #[Test]
    public function a_page_runs_every_widget_through_its_own_permission(): void
    {
        $fixture = $this->sold();
        $owner = $this->makeUser($fixture['tenant']);
        $door = $this->makeUser($fixture['tenant'], 'door');

        $money = $this->actingAs($owner)->postJson('/v1/reports', [
            'name' => 'Revenue',
            'source' => 'orders',
            'definition' => ['dimensions' => ['event'], 'measures' => ['revenue']],
        ])->json();

        $doorReport = $this->actingAs($owner)->postJson('/v1/reports', [
            'name' => 'Scans',
            'source' => 'checkins',
            'definition' => ['dimensions' => ['result'], 'measures' => ['scans']],
        ])->json();

        $page = $this->actingAs($owner)->postJson('/v1/report-pages', [
            'name' => 'Monday morning',
            'widgets' => [
                ['type' => 'bar', 'report_id' => $money['id']],
                ['type' => 'table', 'report_id' => $doorReport['id']],
            ],
        ])->assertCreated()->json();

        $seen = $this->actingAs($door)->getJson('/v1/report-pages/'.$page['id'])->assertOk()->json();

        // The page still renders; the widget the door may not read says so in place.
        $this->assertSame('forbidden_permission', $seen['widgets'][0]['error'] ?? null);
        $this->assertArrayNotHasKey('error', $seen['widgets'][1]);
        $this->assertNotEmpty($seen['widgets'][1]['columns']);
    }

    #[Test]
    public function a_widget_cannot_point_at_another_organisers_report(): void
    {
        $mine = $this->sold();
        $theirs = $this->sold($this->makeTenant('Rival'));
        $me = $this->makeUser($mine['tenant']);
        $them = $this->makeUser($theirs['tenant']);

        $theirReport = $this->actingAs($them)->postJson('/v1/reports', [
            'name' => 'Theirs',
            'source' => 'checkins',
            'definition' => ['dimensions' => ['result'], 'measures' => ['scans']],
        ])->json();

        $page = $this->actingAs($me)->postJson('/v1/report-pages', [
            'name' => 'Sneaky',
            'widgets' => [['type' => 'table', 'report_id' => $theirReport['id']]],
        ])->assertCreated()->json();

        $this->assertSame([], $page['widgets'], 'The widget was dropped, not stored.');
    }

    #[Test]
    public function a_module_can_add_a_source_and_it_obeys_the_same_rules(): void
    {
        // The registry is the only way in, and it takes whatever the enabled modules contribute.
        $sources = app(SourceRegistry::class)->all();

        foreach ($sources as $key => $source) {
            $this->assertNotSame('', $source->permission(), $key.' must declare a permission');
            $this->assertNotEmpty($source->measures(), $key.' must offer something to measure');
        }

        $this->assertNull(app(SourceRegistry::class)->find('nothing_like_this'));
    }

    #[Test]
    public function the_row_cap_is_part_of_the_contract(): void
    {
        $this->assertSame(1000, ReportRunner::MAX_ROWS);
        $this->assertGreaterThan(ReportRunner::MAX_ROWS, ReportRunner::MAX_EXPORT_ROWS);

        $fixture = $this->sold();
        $owner = $this->makeUser($fixture['tenant']);

        // Asking for more than the cap gets the cap, not the number that was asked for.
        $body = $this->actingAs($owner)->postJson('/v1/reports/run', [
            'source' => 'seats_sold',
            'definition' => ['dimensions' => ['section'], 'measures' => ['seats'], 'limit' => 100000],
        ])->assertOk()->json();

        $this->assertSame(ReportRunner::MAX_ROWS, $body['limit']);
    }

    /** A tenant with two seats actually sold, paid for, and one scan at the door. */
    private function sold(?\App\Models\Tenant $tenant = null): array
    {
        $fixture = $this->makeSellableEvent($tenant);
        $event = $fixture['event'];
        $reference = 'wc_'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(8));

        $hold = $this->postJson("/v1/embed/events/{$event->public_id}/holds", [
            // Two seats in *different* rows, so a report grouped by row has more than one row of
            // its own — which is what makes the truncation test mean anything.
            'seat_ids' => [$fixture['seats'][0]->id, $fixture['seats'][5]->id],
            'session_id' => 'sess_'.\Illuminate\Support\Str::random(8),
        ])->assertCreated()->json();

        $api = $this->makeApiClient($fixture['tenant']);

        $body = json_encode(['external_order_id' => $reference, 'hold_token' => $hold['hold_token']]);

        $this->call(
            'POST', '/v1/integrations/woocommerce/orders', [], [], [],
            $this->serverHeaders($this->signedHeaders(
                $api['key_id'], $api['secret'], 'POST', '/v1/integrations/woocommerce/orders', $body
            )),
            $body,
        )->assertCreated();

        // Allocations exist once the money is in: a pending order has held seats, not sold ones.
        $path = '/v1/integrations/woocommerce/orders/'.$reference.'/confirm';
        $confirmBody = json_encode(['buyer' => ['name' => 'Dana Scully', 'email' => 'dana@example.test']]);

        $this->call(
            'POST', $path, [], [], [],
            $this->serverHeaders($this->signedHeaders($api['key_id'], $api['secret'], 'POST', $path, $confirmBody)),
            $confirmBody,
        )->assertOk();

        return $fixture;
    }
}
