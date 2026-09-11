<?php

namespace Tests\Feature;

use App\Domain\Reports\ReportSchedules;
use App\Models\MessageDelivery;
use App\Models\Report;
use App\Models\ReportSchedule;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSeatingFixtures;
use Tests\TestCase;

/**
 * Reports that arrive rather than waiting to be opened.
 *
 * The builder could already answer any question somebody thought to ask it, which was the whole of
 * the problem: somebody had to think to ask. What is checked here is that a schedule is a timer on
 * a *definition* and never a cache of numbers — the rule ADR-0006 is built on, which a thing that
 * fires on its own is the most likely feature to break — and that scheduling a report is never a
 * way to be sent one you may not open.
 */
class ScheduledReportTest extends TestCase
{
    use BuildsSeatingFixtures, RefreshDatabase;

    #[Test]
    public function a_schedule_sends_the_report_to_the_people_named(): void
    {
        Mail::fake();

        $fixture = $this->sold();
        $owner = $this->makeUser($fixture['tenant']);
        $report = $this->savedReport($fixture, $owner);

        $schedule = $this->actingAs($owner)->postJson('/v1/report-schedules', [
            'report_id' => $report['id'],
            'cadence' => 'weekly',
            'weekday' => 1,
            'hour' => 8,
            'recipients' => ['marketing@example.test', 'board@example.test'],
        ])->assertStatus(201)->json();

        $this->assertNotNull($schedule['next_run_at']);

        $result = $this->actingAs($owner)
            ->postJson('/v1/report-schedules/'.$schedule['id'].'/send')
            ->assertOk()
            ->json();

        $this->assertSame(2, $result['sent']);

        app(TenantContext::class)->runAs($fixture['tenant'], function () {
            $deliveries = MessageDelivery::where('kind', 'system.notice')->get();

            $this->assertCount(2, $deliveries);
            $this->assertSame(
                ['board@example.test', 'marketing@example.test'],
                $deliveries->pluck('recipient')->sort()->values()->all(),
            );
        });
    }

    #[Test]
    public function the_email_carries_the_rows_rather_than_a_link_to_a_screen(): void
    {
        Mail::fake();

        $fixture = $this->sold();
        $owner = $this->makeUser($fixture['tenant']);

        $schedule = $this->schedule($fixture, $owner, ['recipients' => ['marketing@example.test']]);

        app(TenantContext::class)->runAs($fixture['tenant'], fn () => app(ReportSchedules::class)
            ->send(ReportSchedule::findOrFail($schedule['id'])));

        $delivery = app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => MessageDelivery::where('kind', 'system.notice')->firstOrFail()
        );

        // The figures themselves, in the body: a report that only said "open the panel" would be a
        // reminder, and the point of this is that somebody reads it on a telephone at seven.
        $this->assertStringContainsString('Stalls', (string) $delivery->preview);
    }

    #[Test]
    public function the_link_in_the_email_downloads_the_spreadsheet_without_signing_in(): void
    {
        Mail::fake();

        $fixture = $this->sold();
        $owner = $this->makeUser($fixture['tenant']);
        $schedule = $this->schedule($fixture, $owner, ['recipients' => ['board@example.test']]);

        $link = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'reports.scheduled.download',
            now()->addDays(ReportSchedules::LINK_DAYS),
            ['schedule' => $schedule['id']],
        );

        // Nobody is signed in: this is clicked from an inbox by a board member with no account.
        $response = $this->get($link)->assertOk();

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Stalls', $csv);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'A BOM, so a spreadsheet on Windows opens it as text.');
    }

    #[Test]
    public function a_link_that_was_not_signed_by_us_is_not_found(): void
    {
        $fixture = $this->sold();
        $owner = $this->makeUser($fixture['tenant']);
        $schedule = $this->schedule($fixture, $owner, ['recipients' => ['board@example.test']]);

        // Not "forbidden": that would confirm the schedule exists to somebody guessing ids.
        $this->get('/reports/scheduled/'.$schedule['id'])->assertNotFound();
        $this->get('/reports/scheduled/'.$schedule['id'].'?signature=nonsense&expires=9999999999')
            ->assertNotFound();
    }

    #[Test]
    public function a_link_that_has_run_out_stops_working(): void
    {
        $fixture = $this->sold();
        $owner = $this->makeUser($fixture['tenant']);
        $schedule = $this->schedule($fixture, $owner, ['recipients' => ['board@example.test']]);

        $link = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'reports.scheduled.download',
            now()->addDays(ReportSchedules::LINK_DAYS),
            ['schedule' => $schedule['id']],
        );

        $this->travel(ReportSchedules::LINK_DAYS + 1)->days();

        // A link that worked for ever would be a permanent copy of the report, handed to whoever
        // the email was forwarded to.
        $this->get($link)->assertNotFound();
    }

    #[Test]
    public function the_numbers_are_the_numbers_now_and_never_a_remembered_copy(): void
    {
        Mail::fake();

        $fixture = $this->sold();
        $owner = $this->makeUser($fixture['tenant']);
        $schedule = $this->schedule($fixture, $owner, ['recipients' => ['board@example.test']]);

        app(TenantContext::class)->runAs($fixture['tenant'], fn () => app(ReportSchedules::class)
            ->send(ReportSchedule::findOrFail($schedule['id'])));

        $first = app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => ReportSchedule::findOrFail($schedule['id'])->last_rows
        );

        // One of the seats comes back after the first send.
        app(TenantContext::class)->runAs($fixture['tenant'], fn () => app(\App\Domain\Orders\OrderService::class)
            ->refund(\App\Models\ExternalOrder::orderByDesc('created_at')->firstOrFail(), null, 'a change of plan'));

        $link = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'reports.scheduled.download',
            now()->addDays(ReportSchedules::LINK_DAYS),
            ['schedule' => $schedule['id']],
        );

        $csv = $this->get($link)->assertOk()->streamedContent();

        /*
         * The spreadsheet is re-run rather than remembered.
         *
         * A schedule that kept its last result would hand somebody a copy of numbers that have
         * since been corrected — the thing ADR-0006 §2 exists to prevent, and a timer does not get
         * an exception to it.
         */
        $this->assertSame(1, $first, 'One row when it was sent.');
        $this->assertStringNotContainsString('Stalls', $csv, 'And nothing sold now that it has been refunded.');
    }

    #[Test]
    public function a_report_somebody_may_not_read_cannot_be_scheduled_to_them(): void
    {
        $fixture = $this->sold();
        $owner = $this->makeUser($fixture['tenant']);
        $report = $this->savedReport($fixture, $owner);

        // A door volunteer sees head counts and not money. Scheduling is not a way round that.
        $door = $this->makeUser($fixture['tenant'], 'door');

        $this->actingAs($door)->postJson('/v1/report-schedules', [
            'report_id' => $report['id'],
            'cadence' => 'daily',
            'recipients' => ['volunteer@example.test'],
        ])->assertNotFound();
    }

    #[Test]
    public function a_schedule_for_a_report_somebody_may_not_read_is_not_even_listed(): void
    {
        $fixture = $this->sold();
        $owner = $this->makeUser($fixture['tenant']);
        $this->schedule($fixture, $owner, ['recipients' => ['board@example.test']]);

        $door = $this->makeUser($fixture['tenant'], 'door');

        // A listing that showed it would leak the report's name and the fact somebody gets it.
        $this->assertSame([], $this->actingAs($door)
            ->getJson('/v1/report-schedules')->assertOk()->json('data'));
    }

    #[Test]
    public function the_hour_is_read_in_the_schedules_own_timezone(): void
    {
        $fixture = $this->sold();
        $owner = $this->makeUser($fixture['tenant']);
        $report = $this->savedReport($fixture, $owner);

        $body = $this->actingAs($owner)->postJson('/v1/report-schedules', [
            'report_id' => $report['id'],
            'cadence' => 'daily',
            'hour' => 8,
            'timezone' => 'Asia/Tehran',
            'recipients' => ['board@example.test'],
        ])->assertStatus(201)->json();

        $next = \Illuminate\Support\Carbon::parse($body['next_run_at'])->setTimezone('Asia/Tehran');

        $this->assertSame(8, $next->hour, 'Eight in the morning where the venue is.');
    }

    #[Test]
    public function the_scheduled_run_sends_what_is_due_and_moves_the_timer_on(): void
    {
        Mail::fake();

        $fixture = $this->sold();
        $owner = $this->makeUser($fixture['tenant']);
        $schedule = $this->schedule($fixture, $owner, [
            'cadence' => 'daily',
            'recipients' => ['board@example.test'],
        ]);

        // Its hour comes round.
        app(TenantContext::class)->runAs($fixture['tenant'], fn () => ReportSchedule::whereKey($schedule['id'])
            ->update(['next_run_at' => now()->subMinute()]));

        $this->artisan('reports:send')->assertSuccessful();

        app(TenantContext::class)->runAs($fixture['tenant'], function () {
            $after = ReportSchedule::firstOrFail();

            $this->assertNotNull($after->last_sent_at);
            $this->assertTrue($after->next_run_at->greaterThan(now()), 'And it is set for tomorrow.');
            $this->assertSame(1, MessageDelivery::where('kind', 'system.notice')->count());
        });
    }

    #[Test]
    public function a_paused_schedule_sends_nothing(): void
    {
        Mail::fake();

        $fixture = $this->sold();
        $owner = $this->makeUser($fixture['tenant']);
        $schedule = $this->schedule($fixture, $owner, ['recipients' => ['board@example.test']]);

        app(TenantContext::class)->runAs($fixture['tenant'], fn () => ReportSchedule::whereKey($schedule['id'])
            ->update(['paused' => true, 'next_run_at' => now()->subMinute()]));

        $this->artisan('reports:send')->assertSuccessful();

        app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => $this->assertSame(0, MessageDelivery::where('kind', 'system.notice')->count())
        );
    }

    #[Test]
    public function a_schedule_that_will_not_run_says_why_and_moves_on(): void
    {
        Mail::fake();

        $fixture = $this->sold();
        $owner = $this->makeUser($fixture['tenant']);
        $schedule = $this->schedule($fixture, $owner, ['recipients' => ['board@example.test']]);

        // The report's source disappears, which is what happens when a module is switched off.
        app(TenantContext::class)->runAs($fixture['tenant'], fn () => Report::query()
            ->update(['source_key' => 'a_module_that_went_away']));

        app(TenantContext::class)->runAs($fixture['tenant'], fn () => app(ReportSchedules::class)
            ->send(ReportSchedule::findOrFail($schedule['id'])));

        app(TenantContext::class)->runAs($fixture['tenant'], function () {
            $after = ReportSchedule::firstOrFail();

            $this->assertNotNull($after->last_error, 'The organiser sees the sentence on their own screen.');
            // Advanced anyway: a schedule stuck on a bad hour would fire every time the command
            // ran, which turns one broken report into an hourly one.
            $this->assertTrue($after->next_run_at->greaterThan(now()));
            $this->assertSame(0, MessageDelivery::where('kind', 'system.notice')->count());
        });
    }

    #[Test]
    public function deleting_the_report_takes_its_schedule_with_it(): void
    {
        $fixture = $this->sold();
        $owner = $this->makeUser($fixture['tenant']);
        $report = $this->savedReport($fixture, $owner);

        $this->actingAs($owner)->postJson('/v1/report-schedules', [
            'report_id' => $report['id'],
            'cadence' => 'daily',
            'recipients' => ['board@example.test'],
        ])->assertStatus(201);

        $this->actingAs($owner)->deleteJson('/v1/reports/'.$report['id'])->assertSuccessful();

        // A timer pointing at a definition that no longer exists is an email nobody can explain.
        app(TenantContext::class)->runAs(
            $fixture['tenant'],
            fn () => $this->assertSame(0, ReportSchedule::count())
        );
    }

    #[Test]
    public function a_monthly_schedule_never_lands_on_a_day_that_some_months_do_not_have(): void
    {
        $fixture = $this->sold();
        $owner = $this->makeUser($fixture['tenant']);
        $report = $this->savedReport($fixture, $owner);

        // 31 would skip February entirely and half the other months besides, and "it did not
        // arrive and nobody knows why" is the worst failure a scheduled report has.
        $this->actingAs($owner)->postJson('/v1/report-schedules', [
            'report_id' => $report['id'],
            'cadence' => 'monthly',
            'day_of_month' => 31,
            'recipients' => ['board@example.test'],
        ])->assertStatus(422);
    }

    /* ------------------------------------------------------------------------------ helpers */

    private function schedule(array $fixture, $owner, array $overrides = []): array
    {
        $report = $this->savedReport($fixture, $owner);

        return $this->actingAs($owner)->postJson('/v1/report-schedules', $overrides + [
            'report_id' => $report['id'],
            'cadence' => 'weekly',
            'weekday' => 1,
            'hour' => 8,
            'recipients' => ['board@example.test'],
        ])->assertStatus(201)->json();
    }

    /**
     * Seats still sold, by section.
     *
     * Filtered to `active` on purpose: without it the source reports released and voided
     * allocations too — which is right for a source that has an `allocation_status` dimension, and
     * would make the check below about re-running mean nothing.
     */
    private function savedReport(array $fixture, $owner): array
    {
        return $this->actingAs($owner)->postJson('/v1/reports', [
            'name' => 'Seats by section',
            'source' => 'seats_sold',
            'definition' => [
                'dimensions' => ['section'],
                'measures' => ['seats', 'revenue'],
                'filters' => ['status' => ['active']],
            ],
        ])->assertStatus(201)->json();
    }

    private function sold(): array
    {
        $fixture = $this->makeSellableEvent();
        $event = $fixture['event'];
        $reference = 'wc_'.Str::lower(Str::random(8));

        $hold = $this->postJson("/v1/embed/events/{$event->public_id}/holds", [
            'seat_ids' => [$fixture['seats'][0]->id, $fixture['seats'][1]->id],
            'session_id' => 'sess_'.Str::random(8),
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

        $path = '/v1/integrations/woocommerce/orders/'.$reference.'/confirm';
        $payload = json_encode(['buyer' => ['name' => 'Dana', 'email' => 'dana@example.test']]);

        $this->call(
            'POST', $path, [], [], [],
            $this->serverHeaders($this->signedHeaders($api['key_id'], $api['secret'], 'POST', $path, $payload)),
            $payload,
        )->assertOk();

        return $fixture;
    }
}
