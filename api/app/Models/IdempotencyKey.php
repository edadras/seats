<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Not tenant-scoped: the record must be findable before the tenant is known (and for anonymous
 * widget calls there is no tenant at all). Isolation comes from `scope`, which always embeds the
 * caller's identity.
 */
class IdempotencyKey extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'scope', 'idempotency_key', 'request_hash', 'endpoint',
        'response_status', 'response_body', 'completed_at', 'expires_at',
    ];

    protected $casts = [
        'response_body' => 'array',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
