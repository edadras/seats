<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Settlement\Settlement;
use App\Http\Controllers\Controller;
use App\Support\Audit\AuditLogger;
use App\Support\Locale\Money;
use App\Support\Pdf\StatementPdf;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The end-of-run screen: what came in, what went back out, and what is owed to whom.
 *
 * Behind `reports.orders.view` — the same permission as every other screen that shows money, so a
 * volunteer given the door list still cannot see the takings.
 *
 * Three shapes of the same figures: the screen, a CSV for whoever does the books, and a PDF
 * statement to send a venue. All three come from one call to the settlement, so they cannot
 * disagree about the total.
 */
class SettlementController extends Controller
{
    public function __construct(
        private readonly Settlement $settlement,
        private readonly StatementPdf $pdf,
        private readonly TenantContext $tenants,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request)
    {
        $this->authorize($request, 'reports.orders.view');

        return response()->json($this->settlement->forPeriod($this->filters($request)));
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorize($request, 'reports.orders.view');

        $filters = $this->filters($request);
        $settlement = $this->settlement->forPeriod($filters);

        $this->audit->record('settlement.exported', null, [
            'from' => $settlement['period']['from'],
            'to' => $settlement['period']['to'],
            'basis' => $settlement['period']['basis'],
            'events' => count($settlement['rows']),
        ]);

        $headings = [
            __('panel.settlement.event'),
            __('panel.settlement.date'),
            __('panel.settlement.currency'),
            __('panel.settlement.orders'),
            __('panel.settlement.seats'),
            __('panel.settlement.seatsRefunded'),
            __('panel.settlement.tickets'),
            __('panel.settlement.discount'),
            __('panel.settlement.fee'),
            __('panel.settlement.tax'),
            __('panel.settlement.charged'),
            __('panel.settlement.refunded'),
            __('panel.settlement.kept'),
            __('panel.settlement.commission'),
            __('panel.settlement.payable'),
        ];

        return response()->streamDownload(function () use ($settlement, $headings) {
            $handle = fopen('php://output', 'wb');

            // The same BOM the other exports write, so a spreadsheet on Windows opens Persian and
            // Arabic headings as text rather than as mojibake.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $headings);

            foreach ($settlement['rows'] as $row) {
                fputcsv($handle, [
                    $row['event']['name'],
                    $row['event']['starts_at'] ? substr($row['event']['starts_at'], 0, 10) : '',
                    $row['currency'],
                    $row['orders'],
                    $row['seats'],
                    $row['seats_refunded'],
                    // Decimal, not minor units: a book-keeper opening this needs 12.50, and a
                    // column of 1250 is a column somebody multiplies by a hundred by mistake.
                    ...array_map(
                        fn (string $key) => $this->decimal($row[$key], $row['currency']),
                        ['tickets', 'discount', 'fee', 'tax', 'charged', 'refunded', 'kept',
                            'commission', 'payable'],
                    ),
                ]);
            }

            fclose($handle);
        }, 'settlement.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** The statement itself — a document, not a screenshot of a table. */
    public function statement(Request $request): Response
    {
        $this->authorize($request, 'reports.orders.view');

        $settlement = $this->settlement->forPeriod($this->filters($request));
        $tenant = $this->tenants->get();

        abort_unless($tenant, 404);

        $this->audit->record('settlement.statement_printed', $tenant, [
            'from' => $settlement['period']['from'],
            'to' => $settlement['period']['to'],
            'events' => count($settlement['rows']),
        ]);

        return response($this->pdf->render($tenant, $settlement), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="settlement.pdf"',
        ]);
    }

    /**
     * A money column a spreadsheet can add up.
     *
     * Plain digits and a full stop, not the reader's numerals: this file is opened by accounting
     * software, and a localised "۱۲٬۵۰" is text, not an amount. The screen and the PDF are where
     * the reader's own formatting belongs.
     */
    private function decimal(int $minorUnits, string $currency): string
    {
        return number_format(
            Money::toDecimal($minorUnits, $currency),
            Money::exponent($currency),
            '.',
            '',
        );
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'event_id' => ['nullable', 'uuid'],
            'basis' => ['nullable', 'in:paid,event'],
        ]);
    }
}
