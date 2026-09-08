<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TenantUser extends Model
{
    use HasFactory, HasUuids;

    public const ROLES = ['owner', 'admin', 'manager', 'viewer', 'checkin'];

    /** Roles allowed to change seating data. */
    public const WRITE_ROLES = ['owner', 'admin', 'manager'];

    protected $fillable = ['tenant_id', 'user_id', 'role', 'last_seen_at'];

    protected $casts = ['last_seen_at' => 'datetime'];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function canWrite(): bool
    {
        return in_array($this->role, self::WRITE_ROLES, true);
    }
}
