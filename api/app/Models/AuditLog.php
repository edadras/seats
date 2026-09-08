<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Deliberately not tenant-scoped at the model level: system actions with no tenant must still be
 * recorded. Reads from the panel go through a query that filters by tenant explicitly.
 */
class AuditLog extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'actor_type', 'actor_id', 'action',
        'subject_type', 'subject_id', 'request_id', 'ip', 'context', 'created_at',
    ];

    protected $casts = ['context' => 'array', 'created_at' => 'datetime'];
}
