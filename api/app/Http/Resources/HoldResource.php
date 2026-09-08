<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class HoldResource extends JsonResource
{
    public function toArray($request): array
    {
        $snapshot = $this->price_snapshot ?? [];
        $decoded = $snapshot['decoded'] ?? [];

        return [
            'hold_token' => $this->token,
            'event_public_id' => $this->event?->public_id,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'extends_used' => $this->extends_used,
            'max_extends' => $this->event?->max_extends,
            'currency' => $this->currency,
            'total_amount' => $this->total_amount,
            'seat_map_version_id' => $this->seat_map_version_id,
            'seats' => $decoded['seats'] ?? [],
            // The storefront prices its cart from this, and only from this (threat T3).
            'price_snapshot' => [
                'payload' => $snapshot['payload'] ?? null,
                'signature' => $snapshot['signature'] ?? null,
                'algorithm' => $snapshot['algorithm'] ?? 'HMAC-SHA256',
            ],
        ];
    }
}
