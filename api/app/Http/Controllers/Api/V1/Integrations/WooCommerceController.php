<?php

namespace App\Http\Controllers\Api\V1\Integrations;

use App\Domain\Orders\OrderService;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ExternalOrderResource;
use App\Models\ExternalOrder;
use Illuminate\Http\Request;

/**
 * The storefront-facing order lifecycle.
 *
 * Every method here can be called more than once for the same order — WooCommerce fires status
 * hooks more than once, the plugin retries on timeouts, and a reconciliation job re-drives
 * anything that looks stuck. None of them may produce a second sale.
 */
class WooCommerceController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    public function store(Request $request)
    {
        $data = $request->validate([
            'external_order_id' => ['required', 'string', 'max:100'],
            'hold_token' => ['required', 'string', 'max:100'],
            'buyer' => ['sometimes', 'array'],
            'buyer.name' => ['nullable', 'string', 'max:200'],
            'buyer.email' => ['nullable', 'email', 'max:200'],
            'buyer.phone' => ['nullable', 'string', 'max:50'],
            'buyer.locale' => ['nullable', 'string', 'max:10'],
            'metadata' => ['sometimes', 'array'],
        ]);

        [$order, $created] = $this->orders->register(
            $request->attributes->get('api_client'),
            $data['external_order_id'],
            $data['hold_token'],
            $data['buyer'] ?? [],
            $data['metadata'] ?? [],
        );

        return response()->json(new ExternalOrderResource($order), $created ? 201 : 200);
    }

    public function show(Request $request, string $externalOrderId)
    {
        return response()->json(new ExternalOrderResource($this->find($request, $externalOrderId)));
    }

    public function confirm(Request $request, string $externalOrderId)
    {
        $data = $request->validate([
            'paid_at' => ['sometimes', 'date'],
            'buyer' => ['sometimes', 'array'],
            'buyer.name' => ['nullable', 'string', 'max:200'],
            'buyer.email' => ['nullable', 'email', 'max:200'],
        ]);

        $order = $this->orders->confirm(
            $this->find($request, $externalOrderId),
            $data['buyer'] ?? [],
            isset($data['paid_at']) ? new \DateTimeImmutable($data['paid_at']) : null,
        );

        // Ticket tokens are returned once, here, to the storefront that owns the order — it needs
        // them to render the QR in the customer's email.
        return response()->json(new ExternalOrderResource($order, includeTicketTokens: true));
    }

    public function cancel(Request $request, string $externalOrderId)
    {
        $data = $request->validate([
            'reason' => ['sometimes', 'in:failed,cancelled,deleted,expired'],
        ]);

        $order = $this->orders->cancel(
            $this->find($request, $externalOrderId),
            $data['reason'] ?? 'cancelled',
        );

        return response()->json(new ExternalOrderResource($order));
    }

    public function refund(Request $request, string $externalOrderId)
    {
        $data = $request->validate([
            'seat_ids' => ['sometimes', 'array'],
            'seat_ids.*' => ['uuid'],
            'reason' => ['sometimes', 'string', 'max:255'],
        ]);

        $order = $this->orders->refund(
            $this->find($request, $externalOrderId),
            $data['seat_ids'] ?? null,
            $data['reason'] ?? 'refund',
        );

        return response()->json(new ExternalOrderResource($order));
    }

    /**
     * Scoped to the calling client, not just the tenant: one tenant's two sites must not be able to
     * confirm or refund each other's orders, and order ids are only unique per client anyway.
     */
    private function find(Request $request, string $externalOrderId): ExternalOrder
    {
        $client = $request->attributes->get('api_client');

        $order = ExternalOrder::with(['hold', 'event', 'allocations.ticket'])
            ->where('api_client_id', $client->id)
            ->where('external_order_id', $externalOrderId)
            ->first();

        if (! $order) {
            throw ApiException::notFound('Unknown order.', 'order_not_found');
        }

        return $order;
    }
}
