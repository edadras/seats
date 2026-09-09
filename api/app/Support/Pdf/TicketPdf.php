<?php

namespace App\Support\Pdf;

use App\Models\ExternalOrder;
use App\Models\Site;
use App\Support\Qr\QrRenderer;
use Mpdf\MpdfException;

/**
 * The buyer's tickets, as a file they keep.
 *
 * Written here rather than left to the browser's "Save as PDF" because a ticket has to survive
 * being forwarded, printed at work, and opened on a phone that has never seen this website. A page
 * you are told to print is not a document.
 *
 * The awkward part is language. A ticket carries an event name somebody typed, and that name can be
 * Persian or Arabic — which means the generator has to embed a font *and* shape the text: choose
 * the right form of every letter from its neighbours, join them, and lay the line out right to
 * left, with the Latin words and the digits inside it still running the other way. That is a text
 * engine. mPDF has one, and it is why it is here rather than a smaller library.
 *
 * One typeface for all six languages: Vazirmatn covers Latin and Arabic script, so a booking with
 * an English venue and a Persian buyer is one font and one set of metrics rather than a fallback
 * chain that differs per reader.
 */
class TicketPdf
{
    public function __construct(
        private readonly QrRenderer $qr,
        private readonly PdfEngine $engine,
    ) {}

    /**
     * @param  array<string, string>  $tokens  plaintext ticket codes, keyed by allocation id
     *
     * @throws MpdfException
     */
    public function render(Site $site, ExternalOrder $order, array $tokens): string
    {
        $locale = app()->getLocale();
        $rtl = \App\Support\Locale\Locales::isRtl($locale);

        $html = view('site.tickets-pdf', [
            'site' => $site,
            'order' => $order,
            'tokens' => $tokens,
            'rtl' => $rtl,
            // A PNG, not the SVG the web page uses: see QrRenderer. A code that scans is the whole
            // point of the document.
            'qr' => fn (string $token) => $this->qr->pngDataUri($token),
        ])->render();

        $pdf = $this->engine->make($rtl, 14);
        $pdf->SetTitle(__('site.yourTickets').' · '.$site->name);
        $pdf->SetAuthor($site->name);
        // Nothing in here is a secret the file should carry beyond its purpose, but the codes are
        // credentials, so the document says not to index it and not to keep it in a cache.
        $pdf->SetCreator('Seatmap');
        $pdf->WriteHTML($html);

        return $pdf->Output('', 'S');
    }
}
