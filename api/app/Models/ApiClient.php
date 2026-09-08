<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** A connected storefront — in practice, one WordPress site. */
class ApiClient extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = ['tenant_id', 'name', 'site_url', 'allowed_origins', 'status', 'last_seen_at'];

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
