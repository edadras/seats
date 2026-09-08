<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Modules\ModuleRegistry;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One organiser's decision about one module: on or off, and what they configured it with.
 *
 * Note what is not here: which modules exist. That is a property of the deployment, discovered from
 * the filesystem. A row for a module nobody deployed is inert, which is the right behaviour when a
 * module is removed from a server that had tenants using it — their settings survive the absence
 * and come back if it returns.
 */
class TenantModule extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'module_key', 'enabled', 'settings', 'disabled_reason', 'disabled_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'settings' => 'array',
        'disabled_at' => 'datetime',
    ];

    /**
     * Settings hold encrypted secrets. Hidden so that a stray `toJson()` somewhere — a debug
     * response, a log line, a queued job payload — cannot carry them out of the process.
     */
    protected $hidden = ['settings'];

    protected static function booted(): void
    {
        // The enabled set is cached per tenant; a change to this row is exactly when that cache is
        // wrong. Switching a payment module off should stop it taking money now, not in five
        // minutes, because the reason someone does it is usually that it is doing something they
        // want stopped.
        static::saved(fn (self $module) => ModuleRegistry::forget($module->tenant_id));
        static::deleted(fn (self $module) => ModuleRegistry::forget($module->tenant_id));
    }
}
