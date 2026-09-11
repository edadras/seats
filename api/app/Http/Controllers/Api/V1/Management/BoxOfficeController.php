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
use App\Models\EventSeatOverride;
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
        private readonly \App\Domain\Availability\BestAvailable $best,
        private readonly TicketMailer $mail,
        private readonly AuditLogger $audit,
        private readonly \App\Domain\Agents\SalesAgents $agents,
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

        $this->assertMaySell($request, $event);

        $states = [];

        /*
         * Asked as the counter, which sees one seat nobody else does: a house seat is blocked to
         * the website and on sale here. That is the whole point of holding one back — somebody is
         * going to be handed it on the night, and the person handing it over works at this window.
         */
        foreach ($this->availability->forEvent($event, counter: true) as $seat) {
            $states[$seat['seat_id']] = $seat;
        }

        // Who each house seat is being kept for, so the clerk reads "Production" and not a chair
        // that is somehow free in a sold-out row. Taken separately rather than added to the
        // availability payload, which the public picker also reads: the label is not the public's.
        $house = EventSeatOverride::where('event_id', $event->id)
            ->whereNotNull('held_for')
            ->pluck('held_for', 'seat_id');

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
                // Null on almost every chair. Where it is set, the seat is sellable here and
                // nowhere else, and the screen says so rather than offering it silently.
                'held_for' => $house[$row->seat_id] ?? null,
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
            'entry_slots' => app(\App\Domain\Events\EntrySlots::class)->forEvent($event, openOnly: true),
        ]);
    }

    /**
     * The same hall the buyer is looking at, for the person at the window.
     *
     * The counter used to draw its own grid of seat buttons — a list of chairs in rows, with no
     * plan, no zoom and no idea where in the room anything was. A clerk taking a telephone booking
     * was describing a hall they could not see, from a screen that looked nothing like the one the
     * caller had open. So the panel runs the buyer's picker instead, and this is what it feeds:
     * the same event, the same geometry, the same shape of answer.
     *
     * Two differences, and both are the counter's whole reason for existing: availability is asked
     * for as the counter, which can see a house seat the website cannot, and each such seat carries
     * the name it is being kept under.
     */
    public function hall(Request $request, Event $event)
    {
        $this->authorize($request, 'orders.sell');
        $this->assertMaySell($request, $event);

        if (! $event->seat_map_version_id) {
            throw ApiException::conflict('map_not_published', 'This event has no published seat map.');
        }

        return response()->json([
            'event' => app(\App\Domain\Events\PickerEvent::class)->forEvent($event),
            'seat_map_version_id' => $event->seat_map_version_id,
            'geometry' => app(\App\Domain\SeatMaps\PublishedGeometry::class)
                ->forVersion($event->seatMapVersion),
        ]);
    }

    /** What is free right now, as the counter sees it. The picker polls this every few seconds. */
    public function hallAvailability(Request $request, Event $event)
    {
        $this->authorize($request, 'orders.sell');
        $this->assertMaySell($request, $event);

        $since = $request->query('since');
        $current = (string) $event->availability_version;

        if (null !== $since && $since === $current) {
            return response()->json(['cursor' => $current, 'full' => false, 'seats' => []]);
        }

        /*
         * Who each house seat is being kept for.
         *
         * Merged in here rather than added to the availability service, whose answer the public
         * picker also reads: the label is not the public's. A chair the website is not allowed to
         * sell and this window is, with somebody's name on it, is the one thing the counter sees
         * that nobody else does.
         */
        $house = EventSeatOverride::where('event_id', $event->id)
            ->whereNotNull('held_for')
            ->pluck('held_for', 'seat_id');

        $seats = array_map(
            fn (array $seat) => $seat + ['held_for' => $house[$seat['seat_id']] ?? null],
            $this->availability->forEvent($event, counter: true),
        );

        return response()->json([
            'cursor' => $current,
            'full' => true,
            'seats' => $seats,
            'areas' => $this->availability->capacityForEvent($event),
            'entry_slots' => app(\App\Domain\Events\EntrySlots::class)->forEvent($event, openOnly: true),
            'price_tier' => app(\App\Domain\Pricing\PriceTiers::class)->describe($event),
        ]);
    }

    /**
     * An agent opening a night they were not given.
     *
     * Asked before anything is drawn rather than after they have chosen four seats and taken
     * somebody's money out of their hand.
     */
    private function assertMaySell(Request $request, Event $event): void
    {
        $agent = $this->agents->forUser($request->user());

        if ($agent && ! $this->agents->maySell($agent, $event)) {
            throw ApiException::denied(
                'agent_event_not_allowed',
                'This agent has not been given this event to sell.',
            );
        }
    }

    /**
     * Sell, reserve or give away seats, in one step.
     *
     * Hold and confirm together: there is nobody to come back later. A window sale that left a
     * hold behind would be a seat locked for ten minutes because the clerk was interrupted.
     */
    /**
     * "Four together" at the window.
     *
     * A suggestion rather than a hold: the person at the counter is looking at the buyer, not at a
     * clock, and a hold taken on their behalf would be a hold they have to remember to release
     * when the conversation goes another way. The seats come back named so they can be read out.
     */
    public function suggest(Request $request, Event $event)
    {
        $this->authorize($request, 'orders.sell');

        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:'.config('seatmap.hold.max_seats')],
            'max_amount' => ['nullable', 'integer', 'min:0'],
            'zone_key' => ['nullable', 'string', 'max:60'],
            'section_key' => ['nullable', 'string', 'max:60'],
            'prefer' => ['nullable', 'in:best,cheapest'],
        ]);

        return response()->json([
            'data' => $this->best->find($event, (int) $data['quantity'], $data),
        ]);
    }

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
            // comp: it is a gift and the order is worth nothing. plan: a deposit now and the rest
            // on dates somebody agreed, which is how a school or a coach party books.
            'payment' => ['required', Rule::in(['paid', 'owed', 'comp', 'plan'])],
            // How it was paid for, which is a different question from whether it was. Only cash
            // touches the drawer, and a till that could not tell a card from a note would report
            // every honest evening several hundred short.
            'method' => ['sometimes', 'nullable', Rule::in(\App\Domain\BoxOffice\Tills::METHODS)],
            'note' => ['nullable', 'string', 'max:200'],
            // Who the party is, as against who signed for it: a door list wants the school.
            'group_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            // The plan, where this is one. Either the shape of the conversation — a deposit, how
            // many payments follow and how far apart — or the dates themselves.
            'plan' => ['sometimes', 'array'],
            'plan.deposit' => ['sometimes', 'integer', 'min:0'],
            'plan.instalments' => ['sometimes', 'integer', 'min:1', 'max:24'],
            'plan.every_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'plan.first_due_on' => ['sometimes', 'nullable', 'date'],
            'plan.deposit_paid' => ['sometimes', 'boolean'],
            'plan.schedule' => ['sometimes', 'array', 'max:24'],
            'plan.schedule.*.amount' => ['required_with:plan.schedule', 'integer', 'min:1'],
            'plan.schedule.*.due_on' => ['required_with:plan.schedule', 'date'],
            'send_tickets' => ['sometimes', 'boolean'],
            // The counter sells timed entry too: somebody walking up at ten past ten still has to
            // be put in a window, and the window still has to have room.
            'entry_slot_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        if (! $event->isSellable()) {
            throw ApiException::conflict('event_not_sellable', 'This event is not on sale.');
        }

        /*
         * An agent sells what they were given, and only as far as they have paid.
         *
         * Checked here, before a single seat is held: an agent who has run out has run out, and
         * finding that out after the hold means a queue to apologise to and an inventory lock to
         * clean up. The price is worked out the same way the sale below does it — from the seats
         * asked for — because a limit checked against a different number from the one charged is
         * not a limit.
         */
        $agent = $this->agents->forUser($request->user());

        if ($agent) {
            $this->agents->assertCanSell(
                $agent,
                $event,
                'comp' === $data['payment'] ? 0 : $this->quoteFor($event, $data),
            );
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
        /*
         * The counter's own channel identity, resolved before the hold rather than after it.
         *
         * It is what makes this a *channel* sale and not an anonymous one: house seats are sold to
         * the box office and to nobody else, and a quota counts what a channel is holding as well
         * as what it has sold. A hold taken with no client is a hold that is neither.
         */
        $client = $this->counterClient($event);

        $hold = $this->holds->create(
            $event,
            $data['seat_ids'] ?? [],
            'counter:'.Str::random(24),
            $client->id,
            $request->ip(),
            $data['areas'] ?? [],
            $data['seat_types'] ?? [],
            $data['area_types'] ?? [],
            $data['entry_slot_id'] ?? null,
        );

        $reference = 'bo-'.Str::lower(Str::random(12));

        /*
         * The till this was rung into, where the person selling has one open.
         *
         * Attached rather than required: a venue that never counts a drawer should not be stopped
         * from selling, and one that does gets its evening added up without anybody remembering to
         * say which shift each sale belonged to.
         */
        $shift = $request->user()
            ? app(\App\Domain\BoxOffice\Tills::class)->current($request->user())
            : null;

        // Cash unless the clerk says otherwise, because at a window it usually is — and a comp or
        // an invoice is not paid at all, so it has no method.
        $method = 'paid' === $data['payment'] ? ($data['method'] ?? 'cash') : null;

        [$order] = $this->orders->register($client, $reference, $hold->token, $data['buyer'], [
            'source' => 'box_office',
            'payment' => $data['payment'],
            'method' => $method,
            'sold_by' => $request->user()?->id,
            'note' => $data['note'] ?? null,
        ]);

        if ($shift) {
            $order->forceFill(['shift_id' => $shift->id])->save();
        }

        if (! empty($data['group_name'])) {
            $order->forceFill(['group_name' => $data['group_name']])->save();
        }

        // Whose sale this is, and on what terms. The rate is copied rather than looked up later:
        // agreeing a new percentage next season must not rewrite what was owed for this one.
        if ($agent) {
            $order->forceFill([
                'sales_agent_id' => $agent->id,
                'agent_rate' => (int) $agent->commission_rate,
            ])->save();
        }

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

        /*
         * The plan is written before the booking is confirmed, because confirming is what mints
         * the tickets — and a plan that owes anything is precisely a booking whose tickets are not
         * due yet. Written the other way round, a party of forty would be handed forty codes for
         * a deposit.
         */
        if ('plan' === $data['payment']) {
            app(\App\Domain\Payments\PaymentPlans::class)->create(
                $order,
                ($data['plan'] ?? []) + ['deposit_paid' => true, 'method' => $data['method'] ?? 'cash'],
                $request->user(),
            );
        }

        $order = $this->orders->confirm($order, $data['buyer'], now());

        $this->audit->record('order.sold_at_counter', $order, [
            'payment' => $data['payment'],
            'method' => $method,
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
            'method' => $method,
            // Which till it was rung into, so the screen can show the running total without
            // asking a second question.
            'shift_id' => $shift?->id,
            'plan' => app(\App\Domain\Payments\PaymentPlans::class)->state($order),
            'seats' => $order->allocations->count(),
            'tickets_sent' => $sent,
        ], 201);
    }

    /** How many places the hold is for, seats and standing together. */
    /**
     * What this basket comes to, before a single seat is held.
     *
     * Priced from the same availability the counter screen was drawn from, so the number an agent
     * is refused against is the number they would have been charged. Approximate in one direction
     * only — a seat that has gone in the meantime makes the hold fail, not the limit wrong.
     */
    private function quoteFor(Event $event, array $data): int
    {
        $wanted = array_flip($data['seat_ids'] ?? []);
        $total = 0;

        if ($wanted) {
            foreach ($this->availability->forEvent($event) as $seat) {
                if (isset($wanted[$seat['seat_id']])) {
                    $total += (int) ($seat['amount'] ?? 0);
                }
            }
        }

        $areas = $this->availability->capacityForEvent($event);

        foreach (($data['areas'] ?? []) as $id => $quantity) {
            foreach ($areas as $area) {
                if (($area['capacity_object_id'] ?? null) === $id) {
                    $total += (int) ($area['amount'] ?? 0) * max(0, (int) $quantity);
                }
            }
        }

        return $total;
    }

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
