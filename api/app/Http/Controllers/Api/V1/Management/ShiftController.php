<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\BoxOffice\Tills;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Shift;
use App\Support\Access\Gate;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The till: opening it, moving cash in and out of it, and counting it at the end of the night.
 *
 * Opening and closing your own drawer is `orders.sell` — it is part of working a window, not a
 * privilege. Seeing *other people's* shifts is `reports.orders.view`, because a list of every
 * clerk's discrepancies is a management report about people and not a working tool.
 *
 * Nothing here lets a closed shift be reopened, recounted or edited. A drawer that came up four
 * short is a finding, and a finding that can be corrected afterwards is not one.
 */
class ShiftController extends Controller
{
    public function __construct(
        private readonly Tills $tills,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Shifts, newest first.
     *
     * Somebody with `orders.sell` alone sees their own; the report permission widens it to
     * everybody's. The narrowing is applied to the query rather than to the answer, because a
     * filter applied afterwards is a filter somebody eventually forgets.
     */
    public function index(Request $request)
    {
        $this->authorize($request, 'orders.sell');

        $query = Shift::with(['user:id,name', 'event:id,name'])->orderByDesc('opened_at')->limit(100);

        if (! app(Gate::class)->allows($request, 'reports.orders.view')) {
            $query->where('user_id', $request->user()->id);
        }

        return response()->json([
            'data' => $query->get()->map(fn (Shift $shift) => $this->tills->summary($shift))->values(),
            'methods' => Tills::METHODS,
        ]);
    }

    /** This person's own open till, or null. The counter screen asks this on the way in. */
    public function current(Request $request)
    {
        $this->authorize($request, 'orders.sell');

        $shift = $this->tills->current($request->user());

        return response()->json(['data' => $shift ? $this->tills->summary($shift) : null]);
    }

    public function show(Request $request, Shift $shift)
    {
        $this->authorize($request, 'orders.sell');
        $this->mine($request, $shift);

        return response()->json($this->tills->summary($shift->load(['user:id,name', 'event:id,name'])));
    }

    public function open(Request $request)
    {
        $this->authorize($request, 'orders.sell');

        $data = $request->validate([
            'currency' => ['required', 'string', 'size:3'],
            // What is in the drawer before anybody has sold anything. In minor units, like every
            // other amount on this platform.
            'opening_float' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'event_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        $event = ! empty($data['event_id']) ? Event::whereKey($data['event_id'])->first() : null;

        $shift = $this->tills->open(
            $request->user(),
            $data['currency'],
            (int) ($data['opening_float'] ?? 0),
            $event,
        );

        $this->audit->record('till.opened', $shift, [
            'opening_float' => (int) $shift->opening_float,
            'currency' => $shift->currency,
            'event' => $event?->name,
        ]);

        return response()->json($this->tills->summary($shift->fresh(['user', 'event'])), 201);
    }

    public function move(Request $request, Shift $shift)
    {
        $this->authorize($request, 'orders.sell');
        $this->mine($request, $shift);

        $data = $request->validate([
            'kind' => ['required', Rule::in(['in', 'out'])],
            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            // Required, not optional: a movement without a reason is the thing an audit asks about
            // first, and "somebody typed 40" is not an answer anybody can give a month later.
            'reason' => ['required', 'string', 'max:200'],
        ]);

        $movement = $this->tills->move(
            $shift,
            $data['kind'],
            (int) $data['amount'],
            $data['reason'],
            $request->user()->id,
        );

        $this->audit->record('till.moved', $shift, [
            'kind' => $movement->kind,
            'amount' => $movement->amount,
            'reason' => $movement->reason,
        ]);

        return response()->json($this->tills->summary($shift->fresh(['user', 'event'])), 201);
    }

    public function close(Request $request, Shift $shift)
    {
        $this->authorize($request, 'orders.sell');
        $this->mine($request, $shift);

        $data = $request->validate([
            'counted_cash' => ['required', 'integer', 'min:0', 'max:100000000'],
            'note' => ['sometimes', 'nullable', 'string', 'max:300'],
        ]);

        $closed = $this->tills->close($shift, (int) $data['counted_cash'], $data['note'] ?? null);
        $summary = $this->tills->summary($closed->load(['user', 'event']));

        // The discrepancy goes in the audit log whichever way it fell: an over is as interesting
        // as a short, and only one of the two ever gets mentioned out loud.
        $this->audit->record('till.closed', $closed, [
            'expected' => $summary['expected_cash'],
            'counted' => $summary['counted_cash'],
            'difference' => $summary['difference'],
        ]);

        return response()->json($summary);
    }

    /**
     * A till somebody else is working is not yours to move money in or out of.
     *
     * The report permission is not a way round this: reading every clerk's evening is a management
     * report, and reaching into their drawer is not the same act.
     */
    private function mine(Request $request, Shift $shift): void
    {
        if ($shift->user_id === $request->user()->id) {
            return;
        }

        if ('GET' === $request->method() && app(Gate::class)->allows($request, 'reports.orders.view')) {
            return;
        }

        throw new \App\Exceptions\ApiException(
            'not_your_till',
            'That is somebody else’s till.',
            403,
        );
    }
}
