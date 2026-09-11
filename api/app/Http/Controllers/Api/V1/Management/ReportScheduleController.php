<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Reports\ReportSchedules;
use App\Domain\Reports\SourceRegistry;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Models\ReportSchedule;
use App\Support\Access\Gate;
use App\Support\Audit\AuditLogger;
use App\Support\Locale\Locales;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * Putting a saved report on a timer.
 *
 * The permission is the report's own source, not a permission of its own: somebody who may not read
 * the revenue report may not arrange for it to be posted to themselves every Monday either. That is
 * the check that makes a schedule safe, and it is made both when one is created and every time the
 * list is read — roles change, and a report scheduled last year by somebody since moved to the door
 * should stop appearing on their screen.
 *
 * The recipients are a different question and a deliberate one. They are addresses, not accounts:
 * a marketing agency, a board member, an auditor. Whoever sets the schedule is vouching for them,
 * which is why the audit log records who did it and who it goes to.
 */
class ReportScheduleController extends Controller
{
    public function __construct(
        private readonly ReportSchedules $schedules,
        private readonly SourceRegistry $sources,
        private readonly TenantContext $tenants,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request)
    {
        $schedules = ReportSchedule::with('report')
            ->orderBy('created_at')
            ->get()
            // Only the ones whose report this person may read. A listing that showed the rest would
            // leak the name of a report and the fact that somebody is receiving it.
            ->filter(fn (ReportSchedule $schedule) => $this->mayRead($request, $schedule->report))
            ->values();

        return response()->json([
            'data' => $schedules->map(fn (ReportSchedule $schedule) => $this->schedules->present($schedule)),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $report = $this->reportOrFail($request, $data['report_id']);

        $tenant = $this->tenants->get();

        $schedule = ReportSchedule::create($this->fields($data) + [
            'report_id' => $report->id,
            // The account's clock and the reader's language, copied now rather than followed: a
            // venue that moves either has not asked for the Monday report to change hour or tongue.
            'timezone' => $data['timezone'] ?? ($tenant?->timezone ?: 'UTC'),
            'locale' => Locales::normalise($data['locale'] ?? ($tenant?->locale ?: app()->getLocale())),
            'created_by' => $request->user()?->id,
        ]);

        $schedule->forceFill(['next_run_at' => $this->schedules->nextRun($schedule)])->save();

        $this->audit->record('report.scheduled', $schedule, [
            'report' => $report->name,
            'cadence' => $schedule->cadence,
            'recipients' => count($schedule->recipients ?: []),
        ]);

        return response()->json($this->schedules->present($schedule->fresh('report')), 201);
    }

    public function update(Request $request, ReportSchedule $schedule)
    {
        $this->assertMayRead($request, $schedule);

        $data = $request->validate($this->rules(partial: true));

        $schedule->forceFill($this->fields($data))->save();

        // Recomputed on every edit: an hour changed at four in the afternoon must not leave
        // yesterday's slot sitting in `next_run_at`, which would fire the moment the command ran.
        $schedule->forceFill(['next_run_at' => $this->schedules->nextRun($schedule)])->save();

        return response()->json($this->schedules->present($schedule->fresh('report')));
    }

    public function destroy(Request $request, ReportSchedule $schedule)
    {
        $this->assertMayRead($request, $schedule);

        $this->audit->record('report.unscheduled', $schedule, [
            'report' => $schedule->report?->name,
        ]);

        $schedule->delete();

        return response()->noContent();
    }

    /**
     * Send it now.
     *
     * Because somebody who has just built a schedule wants to see what will arrive, rather than
     * waiting a week to find out they put the wrong address in. It does not move the timer: this is
     * an extra send, not a substitute for the scheduled one.
     */
    public function sendNow(Request $request, ReportSchedule $schedule)
    {
        $this->assertMayRead($request, $schedule);
        $this->authorize($request, 'messages.send');

        $before = $schedule->next_run_at;
        $result = $this->schedules->send($schedule);

        $schedule->forceFill(['next_run_at' => $before])->save();

        $this->audit->record('report.sent', $schedule, [
            'report' => $schedule->report?->name,
            'sent' => $result['sent'],
        ]);

        return response()->json($this->schedules->present($schedule->fresh('report')) + [
            'sent' => $result['sent'],
        ]);
    }

    /* --------------------------------------------------------------------------- helpers */

    private function validated(Request $request): array
    {
        return $request->validate($this->rules() + [
            'report_id' => ['required', 'uuid'],
        ]);
    }

    private function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'name' => ['nullable', 'string', 'max:120'],
            'cadence' => [$required, 'in:daily,weekly,monthly'],
            'hour' => ['nullable', 'integer', 'min:0', 'max:23'],
            'weekday' => ['nullable', 'integer', 'min:1', 'max:7'],
            // 28 rather than 31: a monthly report set for the 31st would skip February and half the
            // year besides, and "it did not arrive and nobody knows why" is the worst failure a
            // scheduled report has.
            'day_of_month' => ['nullable', 'integer', 'min:1', 'max:28'],
            'timezone' => ['nullable', 'timezone'],
            'locale' => ['nullable', 'string', 'max:12'],
            'recipients' => [$required, 'array', 'min:1', 'max:20'],
            'recipients.*' => ['email', 'max:190'],
            'include_link' => ['sometimes', 'boolean'],
            'paused' => ['sometimes', 'boolean'],
        ];
    }

    private function fields(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'name', 'cadence', 'hour', 'weekday', 'day_of_month',
            'timezone', 'locale', 'recipients', 'include_link', 'paused',
        ]));
    }

    private function reportOrFail(Request $request, string $id): Report
    {
        $report = Report::find($id);

        if (! $report || ! $this->mayRead($request, $report)) {
            // Not found rather than forbidden: whether a report exists is not the business of
            // somebody who may not read it.
            throw ApiException::notFound('That report cannot be found.', 'unknown_report');
        }

        return $report;
    }

    private function assertMayRead(Request $request, ReportSchedule $schedule): void
    {
        $schedule->loadMissing('report');

        if (! $this->mayRead($request, $schedule->report)) {
            throw ApiException::notFound('That schedule cannot be found.', 'unknown_schedule');
        }
    }

    private function mayRead(Request $request, ?Report $report): bool
    {
        if (! $report) {
            return false;
        }

        $source = $this->sources->find($report->source_key);

        return $source && app(Gate::class)->allows($request, $source->permission());
    }
}
