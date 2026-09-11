<?php

namespace App\Http\Controllers;

use App\Domain\Reports\ReportRunner;
use App\Domain\Reports\ReportSchedules;
use App\Domain\Reports\SourceRegistry;
use App\Models\ReportSchedule;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The spreadsheet behind a scheduled report, downloaded from a link in an email.
 *
 * Signed rather than signed in, because it is clicked from an inbox at seven in the morning by
 * somebody who may not have a panel account at all — a board member, an agency, an auditor. The
 * signature is Laravel's own over the schedule and an expiry; a wrong or stale one is a 404 rather
 * than a refusal, because "wrong signature" would confirm that the schedule exists.
 *
 * Bounded by design. A link is good for a week ({@see ReportSchedules::LINK_DAYS}), and the people
 * who received it were chosen by somebody who could already read the report — that is the trade
 * this makes, and the expiry is what keeps it from being a permanent copy handed to whoever the
 * email was forwarded to.
 *
 * The report is re-run here, not fetched: the numbers in the spreadsheet are the numbers now, which
 * is the same promise every other route into this engine makes (ADR-0006 §2).
 */
class ScheduledReportController extends Controller
{
    public function __construct(
        private readonly ReportRunner $runner,
        private readonly SourceRegistry $sources,
        private readonly TenantContext $tenants,
    ) {}

    public function __invoke(Request $request, string $schedule): StreamedResponse
    {
        abort_unless($request->hasValidSignature(), 404);

        // Unscoped on purpose and narrow on purpose: nobody is signed in, so there is no tenant to
        // scope by, and the id came out of a signature this platform wrote.
        $row = $this->tenants->runUnscoped(
            fn () => ReportSchedule::withoutGlobalScopes()->with('report')->find($schedule)
        );

        abort_unless($row && $row->report, 404);

        $tenant = $this->tenants->runUnscoped(fn () => \App\Models\Tenant::find($row->tenant_id));

        abort_unless($tenant && 'active' === $tenant->status, 404);

        [$result, $name] = $this->tenants->runAs($tenant, function () use ($row) {
            $source = $this->sources->find($row->report->source_key);

            abort_unless($source, 404);

            return [
                $this->runner->run($source, $row->report->definition ?? [], ReportRunner::MAX_EXPORT_ROWS),
                $row->label(),
            ];
        });

        $filename = Str::slug($name) ?: 'report';

        return response()->streamDownload(function () use ($result) {
            $handle = fopen('php://output', 'wb');

            // The same BOM every other export writes, so a spreadsheet on Windows opens Persian and
            // Arabic headings as text rather than as mojibake.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, array_column($result['columns'], 'label'));

            foreach ($result['rows'] as $row) {
                fputcsv($handle, array_map(
                    fn (array $column) => $row[$column['alias']] ?? '',
                    $result['columns'],
                ));
            }

            fclose($handle);
        }, $filename.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
