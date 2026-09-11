<?php

namespace App\Domain\Reports;

use App\Domain\Messaging\MessageDispatcher;
use App\Models\Report;
use App\Models\ReportSchedule;
use App\Models\Tenant;
use App\Support\Locale\Dates;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Reports that arrive rather than waiting to be opened.
 *
 * The builder can answer any question somebody thinks to ask it, and that was the whole of the
 * problem: somebody had to think to ask. The Monday figures a marketing manager wanted every Monday
 * were a screen they had to remember to open, and the reports nobody opened were exactly the ones
 * worth reading — a sale that stalled, a night selling badly enough to do something about while
 * there was still time.
 *
 * Three rules shape this:
 *
 * 1. **Re-run, never remembered.** The report runs at the moment it is sent. A schedule that kept
 *    its last result would be a copy of numbers that may since have been corrected, which is the
 *    thing ADR-0006 §2 exists to prevent, and a timer does not get an exception.
 * 2. **A summary, and a link to the rest.** The email carries the first few rows as text and a
 *    signed link back to the export. Not an attachment: an attachment is that same stale copy, in
 *    somebody's inbox for ever, and it is also how a mailbox quota becomes a reporting outage.
 * 3. **Its own clock, frozen.** The hour is read in the timezone the schedule was made in. A venue
 *    that moves its account timezone has not asked for the Monday report to start arriving at a
 *    different hour.
 */
class ReportSchedules
{
    /** How many rows of the answer go in the body of the email. */
    public const PREVIEW_ROWS = 8;

    /** How long the link in the email works for. */
    public const LINK_DAYS = 7;

    public function __construct(
        private readonly ReportRunner $runner,
        private readonly SourceRegistry $sources,
        private readonly MessageDispatcher $messages,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * When this schedule should next fire, in UTC.
     *
     * Worked out in the schedule's own timezone and converted, which is the only way an "eight in
     * the morning" survives a clock change. `$after` is exclusive, so calling this with the moment
     * a report was just sent gives the next one rather than the same one again.
     */
    public function nextRun(ReportSchedule $schedule, ?Carbon $after = null): Carbon
    {
        $zone = $schedule->timezone ?: 'UTC';
        $at = ($after ?: now())->copy()->setTimezone($zone);

        $candidate = $at->copy()->setTime((int) $schedule->hour, 0)->startOfHour();

        $candidate = match ($schedule->cadence) {
            'weekly' => $this->nextWeekly($candidate, (int) ($schedule->weekday ?: 1)),
            'monthly' => $this->nextMonthly($candidate, (int) ($schedule->day_of_month ?: 1)),
            default => $candidate,
        };

        // Today's slot has already gone by, so it is the next one.
        while ($candidate->lessThanOrEqualTo($at)) {
            $candidate = match ($schedule->cadence) {
                'weekly' => $candidate->addWeek(),
                'monthly' => $this->nextMonthly($candidate->copy()->addMonthNoOverflow()->startOfMonth(), (int) ($schedule->day_of_month ?: 1)),
                default => $candidate->addDay(),
            };
        }

        return $candidate->setTimezone('UTC');
    }

    /**
     * Send one, whatever the clock says.
     *
     * Used by the scheduled run and by the "send it now" button, which is the same thing from a
     * person's side: somebody who has just built a schedule wants to see what will arrive before
     * they wait a week to find out.
     *
     * @return array{sent: int, rows: int}
     */
    public function send(ReportSchedule $schedule): array
    {
        $schedule->loadMissing('report');
        $report = $schedule->report;

        if (! $report) {
            return $this->wroteDown($schedule, 0, 0, __('reports.errors.report_gone'));
        }

        $source = $this->sources->find($report->source_key);

        if (! $source) {
            /*
             * The module that owned this report's data has been switched off.
             *
             * Recorded on the schedule rather than thrown: the run goes on to everybody else's
             * reports, and the organiser sees the sentence on their own screen next time they look.
             */
            return $this->wroteDown($schedule, 0, 0, __('reports.errors.source_gone', [
                'source' => $report->source_key,
            ]));
        }

        try {
            $result = $this->runner->run($source, $report->definition ?? [], self::PREVIEW_ROWS * 4);
        } catch (Throwable $e) {
            report($e);

            return $this->wroteDown($schedule, 0, 0, $e->getMessage());
        }

        $sent = 0;

        foreach ($this->addresses($schedule) as $address) {
            $delivery = $this->messages->send(
                'system.notice',
                'email',
                $address,
                [
                    'title' => $this->subject($schedule),
                    'body' => $this->body($schedule, $report, $result),
                    'account' => (string) ($this->tenants->get()?->name ?? ''),
                ],
                $schedule->locale,
            );

            $sent += 'sent' === $delivery->status ? 1 : 0;
        }

        return $this->wroteDown($schedule, $sent, count($result['rows']), null);
    }

    /**
     * Every schedule due to fire, across every account.
     *
     * @return array{sent: int, schedules: int}
     */
    public function run(?Carbon $at = null): array
    {
        $at = $at ?: now();
        $counts = ['sent' => 0, 'schedules' => 0];

        foreach (Tenant::where('status', 'active')->cursor() as $tenant) {
            $this->tenants->runAs($tenant, function () use ($at, &$counts) {
                $due = ReportSchedule::with('report')
                    ->where('paused', false)
                    ->whereNotNull('next_run_at')
                    ->where('next_run_at', '<=', $at)
                    ->get();

                foreach ($due as $schedule) {
                    try {
                        $counts['schedules']++;
                        $counts['sent'] += $this->send($schedule)['sent'];
                    } catch (Throwable $e) {
                        // One schedule that will not send must not stop the rest.
                        report($e);

                        $this->wroteDown($schedule, 0, 0, $e->getMessage());
                    }
                }
            });
        }

        return $counts;
    }

    public function present(ReportSchedule $schedule): array
    {
        return [
            'id' => $schedule->id,
            'report_id' => $schedule->report_id,
            'report' => $schedule->report?->name,
            'name' => $schedule->name,
            'cadence' => $schedule->cadence,
            'hour' => $schedule->hour,
            'weekday' => $schedule->weekday,
            'day_of_month' => $schedule->day_of_month,
            'timezone' => $schedule->timezone,
            'locale' => $schedule->locale,
            'recipients' => $schedule->recipients ?: [],
            'include_link' => $schedule->include_link,
            'paused' => $schedule->paused,
            'next_run_at' => $schedule->next_run_at?->toIso8601String(),
            'last_sent_at' => $schedule->last_sent_at?->toIso8601String(),
            'last_rows' => $schedule->last_rows,
            'last_error' => $schedule->last_error,
        ];
    }

    /* --------------------------------------------------------------------------- internals */

    /**
     * The email itself.
     *
     * Plain text, because that is what the messaging channels carry, and because a table of eight
     * rows reads perfectly well as text and arrives intact on a telephone at seven in the morning.
     */
    private function body(ReportSchedule $schedule, Report $report, array $result): string
    {
        $lines = [];

        $labels = array_map(fn (array $column) => (string) $column['label'], $result['columns']);
        $lines[] = implode(' · ', $labels);
        $lines[] = str_repeat('—', min(60, max(10, mb_strlen(implode(' · ', $labels)))));

        foreach (array_slice($result['rows'], 0, self::PREVIEW_ROWS) as $row) {
            $lines[] = implode(' · ', array_map(
                fn (array $column) => (string) ($row[$column['alias']] ?? ''),
                $result['columns'],
            ));
        }

        if (! $result['rows']) {
            $lines[] = __('reports.scheduled.nothing', [], $schedule->locale);
        }

        if (count($result['rows']) > self::PREVIEW_ROWS) {
            $lines[] = '';
            $lines[] = __('reports.scheduled.andMore', [
                'count' => count($result['rows']) - self::PREVIEW_ROWS,
            ], $schedule->locale);
        }

        if ($schedule->include_link) {
            $lines[] = '';
            $lines[] = __('reports.scheduled.download', [
                'days' => self::LINK_DAYS,
            ], $schedule->locale);
            $lines[] = $this->link($schedule);
        }

        return implode("\n", $lines);
    }

    /**
     * A signed link back to the export, good for a week.
     *
     * Signed rather than authenticated, because it is clicked from an inbox where nobody is signed
     * in — and expiring, because a link that works for ever is a copy of the report handed to
     * whoever the email was forwarded to. The people who receive it were chosen by somebody who
     * could already read the report, which is what makes that trade acceptable; the week is what
     * keeps it bounded.
     */
    private function link(ReportSchedule $schedule): string
    {
        return URL::temporarySignedRoute(
            'reports.scheduled.download',
            now()->addDays(self::LINK_DAYS),
            ['schedule' => $schedule->id],
        );
    }

    private function subject(ReportSchedule $schedule): string
    {
        return __('reports.scheduled.subject', [
            'name' => $schedule->label(),
            'date' => Dates::longWhen(now()->setTimezone($schedule->timezone ?: 'UTC'), $schedule->locale),
        ], $schedule->locale);
    }

    /** @return list<string> */
    private function addresses(ReportSchedule $schedule): array
    {
        return array_values(array_unique(array_filter(
            array_map('trim', (array) ($schedule->recipients ?: [])),
            fn (string $address) => filter_var($address, FILTER_VALIDATE_EMAIL),
        )));
    }

    /** @return array{sent: int, rows: int} */
    private function wroteDown(ReportSchedule $schedule, int $sent, int $rows, ?string $error): array
    {
        $schedule->forceFill([
            'last_sent_at' => now(),
            'last_rows' => $rows,
            'last_error' => $error,
            // Advanced whatever happened, including a failure: a schedule that stuck on a bad hour
            // would fire again every time the command ran, which turns one broken report into an
            // hourly one.
            'next_run_at' => $this->nextRun($schedule, now()),
        ])->save();

        return ['sent' => $sent, 'rows' => $rows];
    }

    private function nextWeekly(Carbon $candidate, int $weekday): Carbon
    {
        $shift = ($weekday - $candidate->isoWeekday() + 7) % 7;

        return $candidate->addDays($shift);
    }

    private function nextMonthly(Carbon $candidate, int $day): Carbon
    {
        $day = max(1, min(28, $day));

        return $candidate->day < $day
            ? $candidate->copy()->day($day)
            : ($candidate->day === $day ? $candidate : $candidate->copy()->addMonthNoOverflow()->day($day));
    }
}
