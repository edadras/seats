<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Webhooks\Webhooks;
use App\Domain\Webhooks\WebhookEvents;
use App\Http\Controllers\Controller;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Where an organiser's own systems are told what happened here.
 *
 * Under `connections.manage` with the API keys, because it is the same job: this screen and that
 * one together are the whole of "connect our box office to something else".
 *
 * The signing secret is in exactly two responses — the one that creates an endpoint and the one
 * that rotates it. There is deliberately no way to read it back.
 */
class WebhookController extends Controller
{
    public function __construct(
        private readonly Webhooks $webhooks,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request)
    {
        $this->authorize($request, 'connections.manage');

        return response()->json([
            'data' => WebhookEndpoint::orderBy('name')->orderBy('created_at')->get()
                ->map(fn (WebhookEndpoint $endpoint) => $this->present($endpoint)),
            // The picker, so the panel never holds its own copy of the catalogue.
            'events' => WebhookEvents::describe(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize($request, 'connections.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'url' => ['required', 'string', 'max:500'],
            'event_types' => ['required', 'array', 'min:1'],
            'event_types.*' => ['string', Rule::in(WebhookEvents::all())],
        ]);

        $made = $this->webhooks->create($data['name'], $data['url'], $data['event_types']);

        $this->audit->record('webhook.created', $made['endpoint'], [
            'url' => $made['endpoint']->url,
            'event_types' => $made['endpoint']->event_types,
        ]);

        return response()->json($this->present($made['endpoint']) + [
            // Shown once, and never again.
            'signing_secret' => $made['secret'],
        ], 201);
    }

    public function update(Request $request, WebhookEndpoint $endpoint)
    {
        $this->authorize($request, 'connections.manage');

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'url' => ['sometimes', 'string', 'max:500'],
            'event_types' => ['sometimes', 'array', 'min:1'],
            'event_types.*' => ['string', Rule::in(WebhookEvents::all())],
            'status' => ['sometimes', Rule::in(['active', 'paused'])],
        ]);

        $saved = $this->webhooks->update($endpoint, $data);

        $this->audit->record('webhook.updated', $saved, array_keys($data));

        return response()->json($this->present($saved));
    }

    public function rotate(Request $request, WebhookEndpoint $endpoint)
    {
        $this->authorize($request, 'connections.manage');

        $secret = $this->webhooks->rotate($endpoint);

        $this->audit->record('webhook.rotated', $endpoint, ['url' => $endpoint->url]);

        return response()->json([
            'signing_secret' => $secret,
            // Said plainly, because it is the one thing about rotation that surprises people.
            'note' => 'The previous secret stopped working the moment this was issued.',
        ]);
    }

    /** Send something now, so an integrator can watch it arrive. */
    public function test(Request $request, WebhookEndpoint $endpoint)
    {
        $this->authorize($request, 'connections.manage');

        return response()->json($this->presentDelivery($this->webhooks->test($endpoint)), 202);
    }

    public function destroy(Request $request, WebhookEndpoint $endpoint)
    {
        $this->authorize($request, 'connections.manage');

        $this->audit->record('webhook.deleted', $endpoint, ['url' => $endpoint->url]);

        $endpoint->delete();

        return response()->noContent();
    }

    /** What was sent, what came back, and when. */
    public function deliveries(Request $request)
    {
        $this->authorize($request, 'connections.manage');

        $data = $request->validate([
            'endpoint_id' => ['sometimes', 'nullable', 'uuid'],
            'status' => ['sometimes', 'nullable', Rule::in(['pending', 'delivered', 'dead'])],
            'event_type' => ['sometimes', 'nullable', 'string', 'max:60'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $deliveries = WebhookDelivery::query()
            ->when($data['endpoint_id'] ?? null, fn ($q, $id) => $q->where('webhook_endpoint_id', $id))
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['event_type'] ?? null, fn ($q, $type) => $q->where('event_type', $type))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(min((int) ($data['per_page'] ?? 25), 100));

        return $this->paginated($deliveries, fn (WebhookDelivery $delivery) => $this->presentDelivery($delivery));
    }

    public function replay(Request $request, WebhookDelivery $delivery)
    {
        $this->authorize($request, 'connections.manage');

        $again = $this->webhooks->replay($delivery);

        $this->audit->record('webhook.replayed', $delivery, ['event_type' => $delivery->event_type]);

        return response()->json($this->presentDelivery($again), 202);
    }

    private function present(WebhookEndpoint $endpoint): array
    {
        return [
            'id' => $endpoint->id,
            'name' => $endpoint->name,
            'url' => $endpoint->url,
            'event_types' => $endpoint->event_types ?? [],
            'status' => $endpoint->status,
            // Why we switched it off, where we did. Null when a person paused it themselves.
            'disabled_reason' => $endpoint->disabled_reason,
            'consecutive_failures' => (int) $endpoint->consecutive_failures,
            'last_delivered_at' => $endpoint->last_delivered_at?->toIso8601String(),
            'last_failed_at' => $endpoint->last_failed_at?->toIso8601String(),
            'last_error' => $endpoint->last_error,
            'created_at' => $endpoint->created_at?->toIso8601String(),
        ];
    }

    private function presentDelivery(WebhookDelivery $delivery): array
    {
        return [
            'id' => $delivery->id,
            'endpoint_id' => $delivery->webhook_endpoint_id,
            'event_type' => $delivery->event_type,
            'status' => $delivery->status,
            'attempts' => (int) $delivery->attempts,
            'response_code' => $delivery->response_code,
            'response_body' => $delivery->response_body,
            'next_attempt_at' => $delivery->next_attempt_at?->toIso8601String(),
            'delivered_at' => $delivery->delivered_at?->toIso8601String(),
            'created_at' => $delivery->created_at?->toIso8601String(),
            // The body as it was sent, so a developer can compare it with what their own log holds.
            'payload' => $delivery->payload,
        ];
    }
}
