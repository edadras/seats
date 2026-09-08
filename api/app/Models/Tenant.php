<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = ['name', 'slug', 'status', 'timezone', 'locale', 'settings'];

    protected $casts = ['settings' => 'array'];

    public function members()
    {
        return $this->hasMany(TenantUser::class);
    }

    public function subscription()
    {
        // A plain ordered hasOne rather than latestOfMany(): that helper tie-breaks with MAX(id),
        // and Postgres has no max() for uuid.
        return $this->hasOne(Subscription::class)->orderByDesc('created_at');
    }

    public function apiClients()
    {
        return $this->hasMany(ApiClient::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
