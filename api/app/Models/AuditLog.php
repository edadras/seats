<?php

namespace App\Models;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Deliberately not tenant-scoped at the model level: system actions with no tenant must still be
 * recorded, and BelongsToTenant would refuse to write them.
 *
 * That makes reading the dangerous direction, so there is exactly one way to read: `visible()`,
 * which fails closed. A bare `AuditLog::query()` in a controller would be a cross-tenant leak, and
 * this is the reason a reviewer can point at.
 */
class AuditLog extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'actor_type', 'actor_id', 'action',
        'subject_type', 'subject_id', 'subject_label', 'request_id', 'ip',
        'context', 'changes', 'created_at',
    ];

    protected $casts = [
        'context' => 'array',
        'changes' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * The rows the current tenant may read. The only supported way to query this table from a
     * request.
     */
    public static function visible(): Builder
    {
        $tenantId = app(TenantContext::class)->id();

        if (! $tenantId) {
            // Fails closed, loudly, in the same spirit as the global scope every other model has:
            // an unscoped read of this table is a mistake, not a feature.
            throw new RuntimeException('Audit log read attempted with no tenant bound.');
        }

        return static::query()->where('tenant_id', $tenantId);
    }
}
