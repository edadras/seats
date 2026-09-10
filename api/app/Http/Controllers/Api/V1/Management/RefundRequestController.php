<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Refunds\RefundRequests;
use App\Http\Controllers\Controller;
use App\Models\RefundRequest;
use App\Support\Locale\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The buyers waiting for an answer.
 *
 * Behind `orders.refund`, because saying yes hands money back and saying no is the other half of
 * the same decision — somebody who may not do the first has no business doing the second either.
 *
 * A request that was granted by the terms the moment it was made appears here too, already
 * answered. It is not a queue of work; it is the record of who asked and what happened.
 */
class RefundRequestController extends Controller
{
    public function __construct(private readonly RefundRequests $requests) {}

    public function index(Request $request)
    {
        $this->authorize($request, 'orders.refund');

        $data = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'approved', 'declined'])],
            'event_id' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $rows = RefundRequest::query()
            ->with(['order:id,external_order_id,buyer,total_amount,currency,status', 'event:id,name'])
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['event_id'] ?? null, fn ($query, $id) => $query->where('event_id', $id))
            // Waiting first: this screen exists so that nobody is left waiting.
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->orderByDesc('created_at')
            ->paginate(min(100, (int) ($data['per_page'] ?? 25)));

        return $this->paginated($rows, fn (RefundRequest $row) => [
            'id' => $row->id,
            'status' => $row->status,
            'reason' => $row->reason,
            'outcome_reason' => $row->outcome_reason,
            'asked_at' => $row->created_at?->toIso8601String(),
            'decided_at' => $row->decided_at?->toIso8601String(),
            'seats' => $row->allocation_ids ? count($row->allocation_ids) : null,
            'event' => $row->event ? ['id' => $row->event->id, 'name' => $row->event->name] : null,
            'order' => $row->order ? [
                'reference' => $row->order->external_order_id,
                'buyer' => $row->order->buyer['name'] ?? null,
                'email' => $row->order->buyer['email'] ?? null,
                'status' => $row->order->status,
                'total' => Money::format(
                    (int) $row->order->total_amount,
                    (string) $row->order->currency
                ),
            ] : null,
        ]);
    }

    public function grant(Request $request, RefundRequest $refundRequest)
    {
        $this->authorize($request, 'orders.refund');

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:300']]);

        return response()->json([
            'status' => $this->requests->grant(
                $refundRequest,
                $request->user(),
                $data['reason'] ?? '',
            )->status,
        ]);
    }

    public function decline(Request $request, RefundRequest $refundRequest)
    {
        $this->authorize($request, 'orders.refund');

        // A reason is required rather than optional: the buyer is owed one, and "declined" with
        // nothing after it is the sentence that generates the telephone call.
        $data = $request->validate(['reason' => ['required', 'string', 'max:300']]);

        return response()->json([
            'status' => $this->requests->decline(
                $refundRequest,
                $request->user(),
                $data['reason'],
            )->status,
        ]);
    }
}
