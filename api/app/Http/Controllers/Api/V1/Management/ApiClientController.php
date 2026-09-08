<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use App\Models\ApiKey;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;

/**
 * Connected storefronts and their credentials.
 *
 * Secrets appear in exactly one response — the one that creates them. There is deliberately no
 * endpoint to read a secret back: if a tenant loses it, they rotate.
 */
class ApiClientController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index()
    {
        return response()->json([
            'data' => ApiClient::with(['keys' => fn ($q) => $q->whereNull('revoked_at')])
                ->orderBy('name')->get()
                ->map(fn (ApiClient $client) => $this->present($client)),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeWrite($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'site_url' => ['nullable', 'url', 'max:255'],
            'allowed_origins' => ['sometimes', 'array'],
            'allowed_origins.*' => ['string', 'max:255'],
        ]);

        $client = ApiClient::create($data);
        $issued = ApiKey::issue($client, 'initial');

        $this->audit->record('api_client.created', $client, ['name' => $client->name]);

        return response()->json($this->present($client->fresh()) + [
            'credentials' => [
                'key_id' => $issued['model']->key_id,
                // Shown once. Never retrievable again (threat T5).
                'secret' => $issued['secret'],
            ],
        ], 201);
    }

    /**
     * Issue an additional key without revoking the old one, so a site can be updated and verified
     * before the previous credential is withdrawn.
     */
    public function rotate(Request $request, ApiClient $client)
    {
        $this->authorizeWrite($request);

        $issued = ApiKey::issue($client, $request->input('label', 'rotated'));

        $this->audit->record('api_key.rotated', $client, ['key_id' => $issued['model']->key_id]);

        return response()->json([
            'key_id' => $issued['model']->key_id,
            'secret' => $issued['secret'],
            'note' => 'The previous key stays valid until you revoke it.',
        ], 201);
    }

    public function revoke(Request $request, ApiClient $client, string $keyId)
    {
        $this->authorizeWrite($request);

        $key = ApiKey::where('api_client_id', $client->id)->where('key_id', $keyId)->firstOrFail();
        $key->forceFill(['revoked_at' => now()])->save();

        $this->audit->record('api_key.revoked', $client, ['key_id' => $keyId]);

        return response()->noContent();
    }

    private function present(ApiClient $client): array
    {
        return [
            'id' => $client->id,
            'name' => $client->name,
            'site_url' => $client->site_url,
            'allowed_origins' => $client->allowed_origins,
            'status' => $client->status,
            'last_seen_at' => $client->last_seen_at?->toIso8601String(),
            'keys' => $client->keys->whereNull('revoked_at')->map(fn (ApiKey $key) => [
                'key_id' => $key->key_id,
                'label' => $key->label,
                'secret_hint' => $key->secret_hint,
                'last_used_at' => $key->last_used_at?->toIso8601String(),
                'expires_at' => $key->expires_at?->toIso8601String(),
            ])->values(),
        ];
    }
}
