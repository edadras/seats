<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Access\Permissions;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A role an organiser invented, because the built-in six did not describe a job somebody actually
 * does at their venue.
 *
 * Its key lives in `tenant_users.role` alongside the built-in keys, so a membership has one column
 * and one resolution path. Reserved keys are refused at the API rather than shadowed here: a custom
 * role called `owner` that quietly held five permissions would be the worst kind of surprise.
 */
class TenantRole extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'key', 'name', 'permissions'];

    protected $casts = ['permissions' => 'array'];

    /** Only real permissions are ever stored, whatever arrived. */
    public function setPermissionsAttribute($value): void
    {
        $this->attributes['permissions'] = json_encode(Permissions::sanitise((array) $value));
    }
}
