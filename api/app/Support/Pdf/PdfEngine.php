<?php

namespace App\Support\Pdf;

use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\MpdfException;

/**
 * One configured PDF engine for every document the platform prints.
 *
 * There were two copies of this — one in the tickets, one in the invoices — differing only in a
 * page margin, which meant a font or a shaping setting fixed in one was still wrong in the other.
 * Any document that needs Persian and Arabic to come out as joined-up writing rather than a row of
 * isolated letters needs exactly this configuration, so it is written once.
 */
class PdfEngine
{
    /**
     * @param  bool  $rtl  the page's own direction; text inside it still decides for itself,
     *                     which is how an English event name sits correctly in a Persian document
     * @param  int  $margin  millimetres, the same on all four sides
     * @param  array<string, mixed>  $overrides  mPDF settings this document needs and the others do
     *                                           not — a designed ticket sets its own page size and
     *                                           orientation, because the picture behind it was made
     *                                           for one shape of paper
     *
     * @throws MpdfException
     */
    public function make(bool $rtl, int $margin = 18, array $overrides = []): Mpdf
    {
        $temp = storage_path('app/mpdf');

        if (! is_dir($temp)) {
            mkdir($temp, 0775, true);
        }

        $pdf = new Mpdf(array_merge([
            'tempDir' => $temp,
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => $margin,
            'margin_right' => $margin,
            'margin_top' => $margin,
            'margin_bottom' => $margin,
            'fontDir' => array_merge(
                (new ConfigVariables())->getDefaults()['fontDir'],
                [resource_path('fonts')]
            ),
            'fontdata' => (new FontVariables())->getDefaults()['fontdata'] + [
                'vazirmatn' => [
                    'R' => 'Vazirmatn-Regular.ttf',
                    'B' => 'Vazirmatn-Bold.ttf',
                    // OpenType layout on: this is what turns a string of Arabic letters into
                    // joined-up writing rather than a row of isolated forms.
                    'useOTL' => 0xFF,
                    'useKashida' => 75,
                ],
            ],
            'default_font' => 'vazirmatn',
            // `array_merge`, not `+`: with the union operator the left-hand array wins every
            // duplicate key, so the overrides would have been silently ignored and a designed
            // ticket would have come out A4 portrait whatever the organiser chose.
        ], $overrides));

        $pdf->SetDirectionality($rtl ? 'rtl' : 'ltr');

        return $pdf;
    }
}
