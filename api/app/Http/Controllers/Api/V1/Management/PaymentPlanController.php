<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Payments\PaymentPlans;
use App\Http\Controllers\Controller;
use App\Models\ExternalOrder;
use App\Models\OrderInstalment;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A deposit, the dates the rest is due on, and the morning somebody works down the list.
 *
 * Reading a plan is reading a booking, so it sits behind `orders.view`. Taking money and moving a
 * date are the counter's work and sit behind `orders.sell`: a plan somebody can quietly rewrite is
 * a plan that cannot be argued from.
 */
class PaymentPlanController extends Controller
{
    public function __construct(private readonly PaymentPlans $plans) {}

    /** Where one booking stands. */
    public function show(Request $request, ExternalOrder $order)
    {
        $this->authorizeAny($request, ['orders.view', 'orders.view.own']);

        /*
         * An agency can agree a plan at its own window, so it can read the one it agreed — and no
         * other. Not found rather than forbidden, for the same reason the orders screen answers
         * that way: whether somebody else's booking exists is not their business either.
         */
        $agent = app(\App\Domain\Agents\SalesAgents::class)->forUser($request->user());

        if ($agent && $order->sales_agent_id !== $agent->id) {
            throw \App\Exceptions\ApiException::notFound('That booking cannot be found.', 'unknown_order');
        }

        return response()->json($this->plans->state($order));
    }

    /** Agree a plan against a booking that has none. */
    public function store(Request $request, ExternalOrder $order)
    {
        $this->authorize($request, 'orders.sell');

        $data = $request->validate([
            'deposit' => ['sometimes', 'integer', 'min:0'],
            'instalments' => ['sometimes', 'integer', 'min:1', 'max:24'],
            'every_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'first_due_on' => ['sometimes', 'nullable', 'date'],
            'deposit_paid' => ['sometimes', 'boolean'],
            'method' => ['sometimes', 'nullable', Rule::in(\App\Domain\BoxOffice\Tills::METHODS)],
            'schedule' => ['sometimes', 'array', 'max:24'],
            'schedule.*.amount' => ['required_with:schedule', 'integer', 'min:1'],
            'schedule.*.due_on' => ['required_with:schedule', 'date'],
        ]);

        $this->plans->create($order, $data, $request->user());

        return response()->json($this->plans->state($order->fresh()), 201);
    }

    /** Somebody has paid one of them. */
    public function pay(Request $request, OrderInstalment $instalment)
    {
        $this->authorize($request, 'orders.sell');

        $data = $request->validate([
            'method' => ['sometimes', 'nullable', Rule::in(\App\Domain\BoxOffice\Tills::METHODS)],
            'note' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);

        $this->plans->pay($instalment, $data, $request->user());

        $order = ExternalOrder::findOrFail($instalment->external_order_row_id);

        return response()->json($this->plans->state($order));
    }

    /** A date moved, or an amount agreed differently — on a step nobody has paid. */
    public function amend(Request $request, OrderInstalment $instalment)
    {
        $this->authorize($request, 'orders.sell');

        $data = $request->validate([
            'amount' => ['sometimes', 'integer', 'min:1'],
            'due_on' => ['sometimes', 'date'],
            'note' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);

        $this->plans->amend($instalment, $data, $request->user());

        $order = ExternalOrder::findOrFail($instalment->external_order_row_id);

        return response()->json($this->plans->state($order));
    }

    /** The chase list: everything still owed, in the order somebody would ring about it. */
    public function outstanding(Request $request)
    {
        $this->authorize($request, 'orders.view');

        $data = $request->validate([
            'state' => ['sometimes', Rule::in(['all', 'due', 'overdue'])],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ]);

        return response()->json([
            'data' => $this->plans->outstanding(
                $data['state'] ?? 'all',
                (int) ($data['limit'] ?? 200),
                app(\App\Domain\Programme\EventManagers::class)->eventIdsFor($request->user()),
            ),
        ]);
    }
}
