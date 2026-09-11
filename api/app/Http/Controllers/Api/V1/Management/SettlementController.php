<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Settlement\Payouts;
use App\Domain\Settlement\GatewayPayouts;
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
        private readonly Payouts $payouts,
    ) {}

    public function index(Request $request)
    {
        $this->authorize($request, 'reports.orders.view');
        app(\App\Domain\Programme\EventManagers::class)->assertNotScoped($request->user());

        return response()->json($this->settlement->forPeriod($this->filters($request)));
    }

    /**
     * What one night took, settled.
     *
     * The same arithmetic as the season's statement, bounded to a single event. It exists because
     * of who reads it: a programme manager runs four nights and is refused the account's settlement,
     * which is the organiser's whole business — but they are not refused *their* money, and a
     * promoter who cannot see what their own concert took has not been given the concert.
     */
    public function forEvent(Request $request, \App\Models\Event $event)
    {
        $this->authorize($request, 'reports.orders.view');

        return response()->json($this->settlement->forEvent($event));
    }

    /**
     * What has actually been paid out, and what is still owed.
     *
     * Read-only here on purpose. A payout is the platform's side of the sentence — an account that
     * could record its own would be an account that could record one that never happened — but it
     * is the organiser's money, so nothing about it is hidden from them: the period, the figures as
     * they were frozen, the bank reference, and a voided one with the reason it was voided.
     */
    /**
     * The statements a card processor has actually paid, and what they do not explain.
     *
     * Behind `reports.orders.view` with the rest of the settlement screen, and for the same reason:
     * this is the money the venue took, line by line, with buyers' payment references on it.
     */
    public function gatewayPayouts(Request $request)
    {
        $this->authorize($request, 'reports.orders.view');
        app(\App\Domain\Programme\EventManagers::class)->assertNotScoped($request->user());

        return response()->json(['data' => app(GatewayPayouts::class)->all()]);
    }

    public function recordGatewayPayout(Request $request)
    {
        $this->authorize($request, 'reports.orders.view');
        app(\App\Domain\Programme\EventManagers::class)->assertNotScoped($request->user());

        $data = $request->validate([
            'gateway' => ['required', 'string', 'max:60'],
            'reference' => ['required', 'string', 'max:160'],
            'currency' => ['required', 'string', 'size:3'],
            'paid_on' => ['required', 'date'],
            // Signed, and allowed to be: a statement that took more back than it paid out is a bad
            // week, not a bad number, and refusing it would leave nowhere to record the week.
            'gross' => ['required', 'integer'],
            'fees' => ['required', 'integer'],
            'net' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:500'],
            'lines' => ['sometimes', 'array', 'max:5000'],
            'lines.*.reference' => ['nullable', 'string', 'max:160'],
            'lines.*.kind' => ['required', 'string', 'in:payment,refund,fee,adjustment'],
            'lines.*.amount' => ['required', 'integer'],
            'lines.*.fee' => ['sometimes', 'integer'],
            'lines.*.occurred_on' => ['nullable', 'date'],
            'lines.*.description' => ['nullable', 'string', 'max:300'],
        ]);

        $payout = app(GatewayPayouts::class)->record(
            $data,
            $data['lines'] ?? [],
            $request->user()?->getAuthIdentifier(),
        );

        $this->audit->record('gateway_payout.recorded', $payout, [
            'reference' => $payout->reference,
            'net' => $payout->net,
        ]);

        return response()->json(
            ['data' => app(GatewayPayouts::class)->reconcile($payout)],
            201,
        );
    }

    public function reconcileGatewayPayout(Request $request, \App\Models\GatewayPayout $payout)
    {
        $this->authorize($request, 'reports.orders.view');
        app(\App\Domain\Programme\EventManagers::class)->assertNotScoped($request->user());

        return response()->json(['data' => app(GatewayPayouts::class)->reconcile($payout)]);
    }

    public function forgetGatewayPayout(Request $request, \App\Models\GatewayPayout $payout)
    {
        $this->authorize($request, 'reports.orders.view');
        app(\App\Domain\Programme\EventManagers::class)->assertNotScoped($request->user());

        $this->audit->record('gateway_payout.removed', $payout, ['reference' => $payout->reference]);

        app(GatewayPayouts::class)->remove($payout);

        return response()->json(['deleted' => true]);
    }

    public function payouts(Request $request)
    {
        $this->authorize($request, 'reports.orders.view');
        app(\App\Domain\Programme\EventManagers::class)->assertNotScoped($request->user());

        $tenant = $this->tenants->idOrFail();

        return response()->json([
            'data' => $this->payouts->forTenant($tenant),
            // Where the next one starts if nobody argues — so an organiser can see which days are
            // still unsettled without counting back through the list themselves.
            'next_from' => $this->payouts->nextFrom($tenant),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorize($request, 'reports.orders.view');
        app(\App\Domain\Programme\EventManagers::class)->assertNotScoped($request->user());

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
        app(\App\Domain\Programme\EventManagers::class)->assertNotScoped($request->user());

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
