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
     *
     * @throws MpdfException
     */
    public function make(bool $rtl, int $margin = 18): Mpdf
    {
        $temp = storage_path('app/mpdf');

        if (! is_dir($temp)) {
            mkdir($temp, 0775, true);
        }

        $pdf = new Mpdf([
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
        ]);

        $pdf->SetDirectionality($rtl ? 'rtl' : 'ltr');

        return $pdf;
    }
}
