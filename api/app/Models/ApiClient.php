<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A storefront selling this organiser's seats.
 *
 * `kind` says which sort: `external` is a shop that signs its requests — in practice a WordPress
 * site — and `storefront` is one of our own hosted sites, which calls the order services in-process
 * and therefore has no key to sign with (ADR-0003 §4).
 */
class ApiClient extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'name', 'kind', 'site_url', 'allowed_origins', 'status', 'last_seen_at',
    ];

    protected $casts = ['allowed_origins' => 'array', 'last_seen_at' => 'datetime'];

    public function keys()
    {
        return $this->hasMany(ApiKey::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function allowsOrigin(string $origin): bool
    {
        return in_array($origin, $this->allowed_origins ?? [], true);
    }
}
