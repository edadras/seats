<?php

namespace App\Support\Pdf;

use App\Domain\Tickets\TicketDesigns;
use App\Domain\Tickets\TicketFields;
use App\Models\ExternalOrder;
use App\Models\Site;
use App\Models\TicketDesign;
use App\Support\Qr\QrRenderer;
use Mpdf\Mpdf;
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
        private readonly TicketDesigns $designs,
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

        /*
         * The night's own ticket, where the venue made one.
         *
         * Two renderers rather than one template with a hundred conditionals in it, because they
         * are genuinely different documents: the default is a flowing page that fits as many
         * passes as the booking has, and a design is one page per seat with everything at a
         * measured position on a picture. Trying to be both is how each ends up slightly wrong.
         */
        if ($design = $order->event ? $this->designs->for($order->event) : null) {
            return $this->designed($site, $order, $tokens, $design, $rtl);
        }

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

    /**
     * One page per seat, on the picture the venue chose.
     *
     * Drawn with mPDF's fixed positioning rather than as flowing HTML: every field has a place the
     * organiser put it, and flowing layout is precisely the thing that would move it. Positions
     * arrive as percentages and become millimetres here, which is the only conversion in the
     * feature and the reason the panel's preview and this document agree.
     *
     * @param  array<string, string>  $tokens
     *
     * @throws MpdfException
     */
    private function designed(
        Site $site,
        ExternalOrder $order,
        array $tokens,
        TicketDesign $design,
        bool $rtl,
    ): string {
        [$width, $height] = $design->pageMillimetres();

        /*
         * No margin: the background is the page, and a margin would print it inside a white frame.
         *
         * The format is handed over already the right way round and the orientation stays `P`.
         * mPDF swaps a format itself when told `L`, so passing both a landscape pair *and* `L`
         * swaps it back to portrait — which is how a landscape design would have printed on a
         * portrait page. `pageMillimetres()` is the one place that decides which way round a page
         * is, and this keeps it that way.
         */
        $pdf = $this->engine->make($rtl, 0, ['format' => [$width, $height]]);

        $pdf->SetTitle(__('site.yourTickets').' · '.$site->name);
        $pdf->SetAuthor($site->name);
        $pdf->SetCreator('Seatmap');

        /*
         * The tickets, loaded once rather than a query per seat.
         *
         * `holder` reads the name written on the ticket, and lazy loading is off across this
         * application — so without this the renderer throws on any caller that did not happen to
         * eager-load the relation, which is most of them. `loadMissing` rather than `load`: the
         * confirmation email already has it, and re-fetching would be a query per booking sent.
         */
        $order->allocations->loadMissing('ticket');

        /*
         * And the venue, for the same reason.
         *
         * `venue` is the one field that reads through the event, and a caller that loaded the event
         * but not its venue is the normal case — a download resolves the booking, not the building.
         */
        $order->event?->loadMissing('venue');

        foreach ($order->allocations as $allocation) {
            $pdf->AddPage();

            $this->background($pdf, $design, $width, $height);

            $values = TicketFields::values(
                $site,
                $order,
                $allocation,
                $tokens[$allocation->id] ?? null,
                fn (string $token) => $this->qr->pngDataUri($token),
            );

            foreach ($design->fields as $field) {
                $this->place($pdf, $field, $values, $width, $height);
            }
        }

        return $pdf->Output('', 'S');
    }

    /**
     * The picture, if it can be had.
     *
     * Wrapped, and a failure is silent on purpose. The background is the part of this document that
     * depends on somebody else's server still being up; the QR and the seat are not. A ticket that
     * refuses to generate because a poster moved is a buyer at a door with nothing, which is a far
     * worse outcome than a ticket on plain paper.
     */
    private function background(Mpdf $pdf, TicketDesign $design, float $width, float $height): void
    {
        if (! $design->background_url) {
            return;
        }

        try {
            $pdf->Image($design->background_url, 0, 0, $width, $height, '', '', true, false);
        } catch (\Throwable $e) {
            logger()->warning('A ticket background could not be drawn.', [
                'event_id' => $design->event_id,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * One field, at the place it was put.
     *
     * @param  array<string, mixed>  $field
     * @param  array<string, string>  $values
     */
    private function place(Mpdf $pdf, array $field, array $values, float $width, float $height): void
    {
        $key = (string) ($field['key'] ?? '');
        $value = $values[$key] ?? '';

        // An empty field prints nothing rather than an empty box: a booking with no arrival window
        // should not leave a gap where one would have been.
        if ('' === $value) {
            return;
        }

        $x = ((float) $field['x'] / 100) * $width;
        $y = ((float) $field['y'] / 100) * $height;
        $w = ((float) $field['width'] / 100) * $width;

        if ('image' === TicketFields::kindOf($key)) {
            // Square, because a QR is square and stretching one is how it stops scanning.
            $pdf->Image($value, $x, $y, $w, $w, '', '', true, false);

            return;
        }

        $align = ['start' => 'left', 'center' => 'center', 'end' => 'right'][$field['align']] ?? 'left';

        $style = sprintf(
            'font-size:%spt;color:%s;text-align:%s;line-height:1.25;%s%s',
            $field['size'],
            $field['colour'],
            $align,
            'bold' === $field['weight'] ? 'font-weight:bold;' : '',
            'code' === TicketFields::kindOf($key) ? 'font-family:monospace;letter-spacing:0.04em;' : '',
        );

        /*
         * `dir="auto"` on every field.
         *
         * The page has a direction and each field decides for itself, which is how a Persian venue
         * name and a Latin booking reference sit correctly on the same ticket. mPDF reads it the
         * way a browser does.
         */
        $pdf->WriteFixedPosHTML(
            '<div dir="auto" style="'.$style.'">'.htmlspecialchars($value, ENT_QUOTES, 'UTF-8').'</div>',
            $x,
            $y,
            $w,
            // Tall enough that a long name wraps rather than being clipped, and overflow is
            // allowed to run past it: a clipped name on a ticket is worse than an uneven one.
            $height - $y,
            'visible'
        );
    }
}
