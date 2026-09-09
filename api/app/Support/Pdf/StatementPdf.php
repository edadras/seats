<?php

namespace App\Support\Pdf;

use App\Models\Tenant;
use Mpdf\MpdfException;

/**
 * A settlement as a document: what came in, what went back, what the platform kept, what is owed.
 *
 * This is the thing an organiser sends a venue or a promoter at the end of a run, so it has to
 * stand on its own — the period it covers, every event in it, and the arithmetic that got to the
 * figure at the bottom, in the reader's language and currency.
 *
 * One page per currency's worth of totals, but one table for the events: an organiser selling in
 * two currencies is settling two amounts, and a column that added them would be a number nobody
 * could bank.
 *
 * @phpstan-type Row array<string, mixed>
 */
class StatementPdf
{
    public function __construct(private readonly PdfEngine $engine) {}

    /**
     * @param  array{rows: list<array<string, mixed>>, totals: list<array<string, mixed>>, period: array<string, ?string>, commission_rate: int}  $settlement
     *
     * @throws MpdfException
     */
    public function render(Tenant $tenant, array $settlement): string
    {
        $locale = app()->getLocale();
        $rtl = \App\Support\Locale\Locales::isRtl($locale);

        $html = view('panel.settlement-pdf', [
            'tenant' => $tenant,
            'settlement' => $settlement,
            'rtl' => $rtl,
            'money' => fn (int $amount, string $currency) => \App\Support\Locale\Money::format(
                $amount, $currency, $locale
            ),
        ])->render();

        $pdf = $this->engine->make($rtl, 16);
        $pdf->SetTitle(__('panel.settlement.title'));
        $pdf->SetAuthor($tenant->name);
        $pdf->SetCreator('Seatmap');
        $pdf->WriteHTML($html);

        return $pdf->Output('', 'S');
    }
}
