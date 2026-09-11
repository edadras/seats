<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = ['name', 'slug', 'status', 'timezone', 'locale', 'settings', 'require_two_factor'];

    protected $casts = [
        'settings' => 'array',
        'require_two_factor' => 'boolean',
        // When this account stopped selling, and when there will be nothing left of it.
        // {@see \App\Domain\Accounts\AccountClosure} for what sits between the two.
        'closed_at' => 'datetime',
        'erase_after' => 'datetime',
        // Who this account's email comes from, and how far along proving it they are.
        // {@see \App\Domain\Messaging\SenderIdentity}.
        'sender_verified_at' => 'datetime',
        'sender_code_expires_at' => 'datetime',
        'sender_code_attempts' => 'integer',
    ];

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
