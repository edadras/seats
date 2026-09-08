<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WebhookDelivery extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'webhook_endpoint_id', 'event_type', 'payload', 'status',
        'attempts', 'response_code', 'response_body', 'next_attempt_at', 'delivered_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'next_attempt_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function endpoint()
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
