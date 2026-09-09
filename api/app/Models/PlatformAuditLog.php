<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * What an operator did.
 *
 * Separate from the tenants' own audit logs, and written even when the thing being done happened
 * inside a tenant: an organiser's log answers "who in my account did this", and this one answers
 * "who at the platform touched my account", which is a different question and usually a more
 * pointed one.
 */
class PlatformAuditLog extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['user_id', 'action', 'tenant_id', 'context', 'ip', 'created_at'];

    protected $casts = ['context' => 'array', 'created_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class)->withoutGlobalScope('tenant');
    }

    public static function write(?string $userId, string $action, ?string $tenantId, array $context = [], ?string $ip = null): self
    {
        return static::create([
            'user_id' => $userId,
            'action' => $action,
            'tenant_id' => $tenantId,
            'context' => $context,
            'ip' => $ip,
            'created_at' => now(),
        ]);
    }
}
