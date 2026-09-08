<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Something a module did wrong, kept so it is not silent.
 *
 * A module that throws does not fail the request that triggered it (ADR-0004 §5) — but swallowing
 * without recording is how a messaging module that stopped sending tickets goes on looking like one
 * that works. This is what the panel shows as a module's health.
 */
class ModuleFailure extends Model
{
    use BelongsToTenant, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'module_key', 'extension_point', 'operation', 'message', 'context', 'created_at',
    ];

    protected $casts = [
        'context' => 'array',
        'created_at' => 'datetime',
    ];
}
