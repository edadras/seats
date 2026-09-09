<?php

namespace App\Support\Pdf;

use App\Models\Invoice;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
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

        $pdf = $this->engine($rtl);
        $pdf->SetTitle(__('site.invoice.title').' '.$invoice->number);
        $pdf->SetAuthor($invoice->issuer['name'] ?? '');
        $pdf->SetCreator('Seatmap');
        $pdf->WriteHTML($html);

        return $pdf->Output('', 'S');
    }

    /**
     * @throws MpdfException
     */
    private function engine(bool $rtl): Mpdf
    {
        $temp = storage_path('app/mpdf');

        if (! is_dir($temp)) {
            mkdir($temp, 0775, true);
        }

        $pdf = new Mpdf([
            'tempDir' => $temp,
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 18,
            'margin_right' => 18,
            'margin_top' => 18,
            'margin_bottom' => 18,
            'fontDir' => array_merge(
                (new ConfigVariables())->getDefaults()['fontDir'],
                [resource_path('fonts')]
            ),
            'fontdata' => (new FontVariables())->getDefaults()['fontdata'] + [
                'vazirmatn' => [
                    'R' => 'Vazirmatn-Regular.ttf',
                    'B' => 'Vazirmatn-Bold.ttf',
                    'useOTL' => 0xFF,
                    'useKashida' => 75,
                ],
            ],
            'default_font' => 'vazirmatn',
        ]);

        $pdf->SetDirectionality($rtl ? 'rtl' : 'ltr');

        return $pdf;
    }
}
