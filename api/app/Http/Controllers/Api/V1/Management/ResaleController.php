<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Resale\Resales;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\ResaleListing;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;

/**
 * What is being offered back to the public tonight, for the people who run the night.
 *
 * A read and one write, and the write is only ever *taking a seat off sale* — there is nothing
 * here that puts one up. Listing a ticket is the buyer's decision about the buyer's ticket, and a
 * screen that let staff list somebody else's seat would be a screen for selling a customer's
 * property without asking them.
 *
 * Behind `orders.view` to look and `orders.refund` to withdraw. Withdrawing is grouped with
 * refunds rather than with the box office because it is the same kind of act: undoing something a
 * buyer did to their own booking, usually because they have rung up and asked.
 */
class ResaleController extends Controller
{
    public function __construct(
        private readonly Resales $resales,
        private readonly AuditLogger $audit,
    ) {}

    /** Every listing for one night, open ones first by the time they went up. */
    public function index(Request $request, Event $event)
    {
        $this->authorize($request, 'orders.view');

        return response()->json($this->resales->forEvent($event));
    }

    /**
     * Take one back off sale.
     *
     * The seat was the seller's the whole time it sat there, so this gives nothing back and takes
     * nothing away: it stops the seat being offered, and that is all.
     */
    public function withdraw(Request $request, Event $event, string $listing)
    {
        $this->authorize($request, 'orders.refund');

        // Eagerly, because lazy loading is off and the audit line below names the seat.
        $found = ResaleListing::with('allocation')->where('event_id', $event->id)->findOrFail($listing);

        $this->resales->withdraw($found);

        $this->audit->record('resale.withdrawn', $found, [
            'event_id' => $event->id,
            'seat' => trim(($found->allocation?->row_name ?? '').' '.($found->allocation?->seat_label ?? '')),
        ]);

        return response()->json(['data' => ['id' => $found->id, 'state' => $found->state]]);
    }
}
