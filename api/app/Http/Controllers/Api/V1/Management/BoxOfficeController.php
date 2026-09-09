<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Availability\AvailabilityService;
use App\Domain\Events\TicketTypes;
use App\Domain\Inventory\HoldService;
use App\Domain\Orders\OrderService;
use App\Domain\Orders\OrderTotals;
use App\Domain\Sites\TicketMailer;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use App\Models\Event;
use App\Models\ExternalOrder;
use App\Models\Site;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Selling at the window.
 *
 * Everything this platform could do until now assumed a buyer with a browser and a card. A box
 * office sells to the person in front of it: cash, a card on the venue's own terminal, an invoice
 * to a school, or nothing at all because the seat is for the director's mother.
 *
 * It is not a second order path. The counter makes a hold through the same HoldService — with the
 * same locking, the same price snapshot and the same refusal when somebody else got there first —
 * and confirms it through the same OrderService, so a seat sold at the window is a seat sold, with
 * a ticket, a QR code and a row in every report. What differs is only what is written down about
 * the money.
 */
class BoxOfficeController extends Controller
{
    public function __construct(
        private readonly HoldService $holds,
        private readonly OrderService $orders,
        private readonly AvailabilityService $availability,
        private readonly TicketMailer $mail,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * The hall as somebody at a counter needs it: what is free, what it costs, who it is for.
     *
     * Seats already sold or held are sent too, marked as such, because a counter clerk asked for
     * "A5" needs to be told it has gone rather than to find it missing from a list.
     */
    public function counter(Request $request, Event $event)
    {
        $this->authorize($request, 'orders.sell');

        $states = [];

        foreach ($this->availability->forEvent($event) as $seat) {
            $states[$seat['seat_id']] = $seat;
        }

        $sections = [];

        foreach ($this->seatRows($event) as $row) {
            $state = $states[$row->seat_id] ?? null;

            $sections[$row->section_key] ??= [
                'key' => $row->section_key,
                'name' => $row->section_name,
                'rows' => [],
            ];

            $sections[$row->section_key]['rows'][$row->row_key] ??= [
                'key' => $row->row_key,
                'name' => $row->row_name,
                'seats' => [],
            ];

            $sections[$row->section_key]['rows'][$row->row_key]['seats'][] = [
                'id' => $row->seat_id,
                'label' => $row->label,
                'accessible' => (bool) $row->accessible,
                'state' => $state['state'] ?? 'unavailable',
                'amount' => isset($state['amount']) ? (int) $state['amount'] : null,
            ];
        }

        return response()->json([
            'currency' => $event->currency,
            // array_merge, not `+`: the union operator keeps the left-hand `rows` and would send
            // the keyed map the grouping built rather than the list a client can iterate.
            'sections' => array_values(array_map(
                fn (array $section) => array_merge($section, ['rows' => array_values($section['rows'])]),
                $sections
            )),
            'areas' => $this->availability->capacityForEvent($event),
            'ticket_types' => TicketTypes::forEvent($event),
        ]);
    }

    /**
     * Sell, reserve or give away seats, in one step.
     *
     * Hold and confirm together: there is nobody to come back later. A window sale that left a
     * hold behind would be a seat locked for ten minutes because the clerk was interrupted.
     */
    public function sell(Request $request, Event $event)
    {
        $this->authorize($request, 'orders.sell');

        $data = $request->validate([
            'seat_ids' => ['sometimes', 'array', 'max:'.config('seatmap.hold.max_seats')],
            'seat_ids.*' => ['uuid'],
            'areas' => ['sometimes', 'array', 'max:20'],
            'areas.*' => ['integer', 'min:1'],
            'seat_types' => ['sometimes', 'array'],
            'seat_types.*' => ['uuid'],
            'area_types' => ['sometimes', 'array', 'max:20'],
            'area_types.*' => ['array', 'max:20'],
            'area_types.*.*' => ['integer', 'min:1'],
            'buyer.name' => ['required', 'string', 'max:120'],
            'buyer.email' => ['nullable', 'email', 'max:190'],
            'buyer.phone' => ['nullable', 'string', 'max:40'],
            // paid: the money was taken elsewhere. owed: they will pay on the night.
            // comp: it is a gift and the order is worth nothing.
            'payment' => ['required', Rule::in(['paid', 'owed', 'comp'])],
            'note' => ['nullable', 'string', 'max:200'],
            'send_tickets' => ['sometimes', 'boolean'],
        ]);

        if (! $event->isSellable()) {
            throw ApiException::conflict('event_not_sellable', 'This event is not on sale.');
        }

        $wantsEmail = $request->boolean('send_tickets');

        if ($wantsEmail && empty($data['buyer']['email'])) {
            throw ApiException::unprocessable(
                'email_required',
                'An email address is needed to send the tickets.'
            );
        }

        /*
         * A session id nobody else can collide with.
         *
         * Holds are limited per browser session, and every counter sale sharing one identifier
         * would mean the fourth sale of the evening being refused as "too many carts".
         */
        $hold = $this->holds->create(
            $event,
            $data['seat_ids'] ?? [],
            'counter:'.Str::random(24),
            null,
            $request->ip(),
            $data['areas'] ?? [],
            $data['seat_types'] ?? [],
            $data['area_types'] ?? [],
        );

        $client = $this->counterClient($event);
        $reference = 'bo-'.Str::lower(Str::random(12));

        [$order] = $this->orders->register($client, $reference, $hold->token, $data['buyer'], [
            'source' => 'box_office',
            'payment' => $data['payment'],
            'sold_by' => $request->user()?->id,
            'note' => $data['note'] ?? null,
        ]);

        // A comp is worth nothing and must say so before the ticket exists: a report that counted
        // free seats as revenue would overstate the evening by exactly the generosity of the house.
        $totals = OrderTotals::for(
            $event,
            'comp' === $data['payment'] ? 0 : (int) $hold->total_amount,
            0,
            $this->places($hold),
        );

        $order->forceFill([
            'total_amount' => $totals->total,
            'metadata' => ($order->metadata ?? []) + ['totals' => $totals->toArray()],
        ])->save();

        $order = $this->orders->confirm($order, $data['buyer'], now());

        $this->audit->record('order.sold_at_counter', $order, [
            'payment' => $data['payment'],
            'seats' => $order->allocations->count(),
            'amount' => $totals->total,
        ]);

        $sent = false;

        if ($wantsEmail) {
            $site = Site::where('tenant_id', $event->tenant_id)->orderBy('created_at')->first();

            if ($site) {
                $this->mail->send($site, $order);
                $sent = true;
            }
        }

        return response()->json([
            'reference' => $order->external_order_id,
            'id' => $order->id,
            'status' => $order->status,
            'total_amount' => (int) $order->total_amount,
            'currency' => $order->currency,
            'payment' => $data['payment'],
            'seats' => $order->allocations->count(),
            'tickets_sent' => $sent,
        ], 201);
    }

    /** How many places the hold is for, seats and standing together. */
    private function places($hold): int
    {
        $snapshot = $hold->price_snapshot['decoded'] ?? [];
        $places = count($snapshot['seats'] ?? []);

        foreach ($snapshot['areas'] ?? [] as $area) {
            $places += max(1, (int) ($area['quantity'] ?? 1));
        }

        return $places;
    }

    /**
     * The client the counter sells through, made on first use.
     *
     * Orders are registered against an API client — that is how the order lifecycle identifies a
     * seller and how `external_order_id` stays unique per seller. The counter is a seller like any
     * other, and giving it its own client is what makes "sold at the window" answerable in a
     * report without a second flag on every order.
     */
    private function counterClient(Event $event): ApiClient
    {
        $client = ApiClient::where('kind', 'box_office')->first();

        if ($client) {
            return $client;
        }

        return ApiClient::create([
            'tenant_id' => $event->tenant_id,
            'name' => __('panel.boxOffice.clientName'),
            'kind' => 'box_office',
            'status' => 'active',
        ]);
    }

    /** @return list<object> */
    private function seatRows(Event $event): array
    {
        if (! $event->seat_map_version_id) {
            return [];
        }

        return DB::select(<<<'SQL'
            SELECT
                s.id AS seat_id,
                s.label,
                s.accessible,
                r.key AS row_key,
                r.name AS row_name,
                sec.key AS section_key,
                sec.name AS section_name
            FROM seat_placements sp
            JOIN seats s ON s.id = sp.seat_id
            JOIN seat_rows r ON r.id = s.seat_row_id
            JOIN sections sec ON sec.id = r.section_id
            WHERE sp.seat_map_version_id = :version_id
            ORDER BY sec.name, r.name, LPAD(s.label, 12, '0')
        SQL, ['version_id' => $event->seat_map_version_id]);
    }
}
