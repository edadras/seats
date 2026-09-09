<?php

namespace App\Support\Pdf;

use App\Models\Invoice;
use Mpdf\MpdfException;

/**
 * An invoice as a document somebody can file.
 *
 * The same engine as the tickets, and for the same reason: this has to come out right in Persian
 * and Arabic as well as in Latin scripts, and a browser's print dialogue is not something a server
 * can rely on. Everything it prints comes from the invoice row, which was frozen when the number
 * was issued — nothing here reads a live event, site or order.
 */
class InvoicePdf
{
    public function __construct(private readonly PdfEngine $engine) {}

    /**
     * @throws MpdfException
     */
    public function render(Invoice $invoice): string
    {
        $locale = app()->getLocale();
        $rtl = \App\Support\Locale\Locales::isRtl($locale);

        $html = view('site.invoice-pdf', [
            'invoice' => $invoice,
            'rtl' => $rtl,
            'money' => fn (int $amount) => \App\Support\Locale\Money::format(
                $amount, (string) $invoice->currency, $locale
            ),
        ])->render();

        $pdf = $this->engine->make($rtl, 18);
        $pdf->SetTitle(__('site.invoice.title').' '.$invoice->number);
        $pdf->SetAuthor($invoice->issuer['name'] ?? '');
        $pdf->SetCreator('Seatmap');
        $pdf->WriteHTML($html);

        return $pdf->Output('', 'S');
    }
}
