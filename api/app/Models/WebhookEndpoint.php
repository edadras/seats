<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WebhookEndpoint extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'api_client_id', 'name', 'url', 'signing_secret',
        'event_types', 'status', 'consecutive_failures',
        'last_delivered_at', 'last_failed_at', 'last_error', 'disabled_reason',
    ];

    protected $hidden = ['signing_secret'];

    // Encrypted rather than hashed: outgoing deliveries have to be signed with it.
    protected $casts = [
        'event_types' => 'array',
        'signing_secret' => 'encrypted',
        'last_delivered_at' => 'datetime',
        'last_failed_at' => 'datetime',
    ];

    public function deliveries()
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function subscribesTo(string $eventType): bool
    {
        $types = $this->event_types ?? [];

        return $types === [] || in_array($eventType, $types, true) || in_array('*', $types, true);
    }
}
