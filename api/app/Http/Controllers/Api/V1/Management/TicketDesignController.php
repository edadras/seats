<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Tickets\TicketDesigns;
use App\Domain\Tickets\TicketFields;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\ExternalOrder;
use App\Models\Site;
use App\Models\TicketDesign;
use App\Support\Audit\AuditLogger;
use App\Support\Pdf\TicketPdf;
use Illuminate\Http\Request;

/**
 * The ticket one night prints.
 *
 * Behind `events.manage` rather than a permission of its own: designing the ticket for a concert is
 * one of the things running that concert consists of, and a venue where the person who sets the
 * prices cannot choose the artwork is a venue inventing an approval step nobody asked for.
 *
 * The preview is the part worth building carefully. An organiser drags eight fields onto a
 * photograph and has no way to know whether the result is a document until they have sold a ticket
 * to themselves — so this renders the real thing, through the real renderer, with a made-up
 * booking. Anything else is a picture of a ticket rather than the ticket.
 */
class TicketDesignController extends Controller
{
    public function __construct(
        private readonly TicketDesigns $designs,
        private readonly AuditLogger $audit,
    ) {}

    public function show(Request $request, Event $event)
    {
        $this->authorize($request, 'events.view');

        $design = $this->designs->for($event);

        return response()->json([
            'design' => $design ? $this->present($design) : null,
            // What a design *could* say, so the panel offers the list rather than keeping a second
            // copy of it that drifts from the one the renderer reads.
            'fields' => TicketFields::keys(),
            /*
             * Each paper with its millimetres, not only its name.
             *
             * The board in the panel is drawn at the page's own `aspect-ratio`, and a name alone
             * would leave the screen guessing it — A4, A5 and A6 all happen to be within half a
             * per cent of each other, so a guess looks right until somebody chooses letter, which
             * is nearly a centimetre squarer. The shape comes from the same constant the renderer
             * measures the document with.
             */
            'pages' => collect(TicketDesign::PAGES)
                ->map(fn (array $mm, string $name) => [
                    'name' => $name,
                    'width' => $mm[0],
                    'height' => $mm[1],
                ])
                ->values()
                ->all(),
            // A layout for somebody who has just chosen a picture and does not want an empty page.
            'starter' => $this->designs->starter(),
        ]);
    }

    public function update(Request $request, Event $event)
    {
        $this->authorize($request, 'events.manage');

        $data = $request->validate([
            'background_url' => ['nullable', 'string', 'max:1024'],
            'page_size' => ['sometimes', 'string', 'max:12'],
            'orientation' => ['sometimes', 'string', 'max:12'],
            'fields' => ['sometimes', 'array', 'max:40'],
            'fields.*' => ['array'],
        ]);

        $design = $this->designs->save($event, $data);

        $this->audit->record('event.ticket_design_set', $event, [
            'event' => $event->name,
            'fields' => count($design->fields),
        ]);

        return response()->json(['design' => $this->present($design)]);
    }

    /**
     * Back to the platform's own ticket.
     *
     * Deleting the design rather than emptying it, so "no design" is one state rather than two —
     * a row with no fields and no background would print a blank page, which is not what anybody
     * pressing this means.
     */
    public function destroy(Request $request, Event $event)
    {
        $this->authorize($request, 'events.manage');

        $this->designs->forget($event);

        $this->audit->record('event.ticket_design_cleared', $event, ['event' => $event->name]);

        return response()->json(['design' => null]);
    }

    /**
     * The document itself, from a booking that does not exist.
     *
     * A real booking is not used even when one is to hand: a preview that prints somebody's name
     * and their ticket code is a preview that leaks a credential to whoever is looking at the
     * screen, and an organiser designing next season's ticket has no booking to print anyway.
     */
    public function preview(Request $request, Event $event)
    {
        $this->authorize($request, 'events.view');

        $site = Site::query()->orderByDesc('created_at')->first();

        if (! $site) {
            throw \App\Exceptions\ApiException::unprocessable(
                'no_site',
                __('errors.no_site'),
            );
        }

        $pdf = app(TicketPdf::class)->render($site, $this->specimen($event), [
            'specimen' => str_repeat('S', 32),
        ]);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            // Shown rather than downloaded: this is a thing somebody is looking at while they drag
            // fields around, not a file they are collecting.
            'Content-Disposition' => 'inline; filename="ticket-preview.pdf"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * A booking that was never made, with one seat on it.
     *
     * Built in memory and never saved. The renderer wants an order with allocations on it, and the
     * alternative — writing a row and deleting it — is a specimen booking that survives a crash and
     * turns up in somebody's takings.
     */
    private function specimen(Event $event): ExternalOrder
    {
        $order = new ExternalOrder([
            'event_id' => $event->id,
            'external_order_id' => 'SPECIMEN',
            'currency' => $event->currency,
            'buyer' => ['name' => __('panel.ticketDesign.specimenBuyer')],
        ]);

        $order->setRelation('event', $event);

        $allocation = new \App\Models\Allocation([
            'event_id' => $event->id,
            'section_name' => __('panel.ticketDesign.specimenSection'),
            'row_name' => 'A',
            'seat_label' => '12',
            'quantity' => 1,
            'amount' => 2500,
            'currency' => $event->currency,
        ]);

        // The renderer keys its tokens by allocation id, and this one has none until it is saved.
        $allocation->id = 'specimen';
        $allocation->setRelation('ticket', null);

        /*
         * An Eloquent collection, not `collect()`.
         *
         * A real `allocations` relation hands back `Eloquent\Collection`, and the renderer uses that
         * — `loadMissing` to fetch the tickets in one query rather than one per seat. A plain
         * support collection looks identical until it is asked to do the one thing a relation can
         * do, and then the preview is a 500 while every real booking prints.
         */
        $order->setRelation('allocations', new \Illuminate\Database\Eloquent\Collection([$allocation]));

        return $order;
    }

    /** @return array<string, mixed> */
    private function present(TicketDesign $design): array
    {
        return [
            'background_url' => $design->background_url,
            'page_size' => $design->page_size,
            'orientation' => $design->orientation,
            'fields' => $design->fields,
        ];
    }
}
