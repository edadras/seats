<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A hostname pointing at a site.
 *
 * Unique platform-wide, because it is the only routing key a public request has. Not routable until
 * `verified_at` is set: DNS alone proves nothing about who owns a name, and serving an unverified
 * host would let anyone park their traffic on us — or claim a name they do not own.
 */
class SiteDomain extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'site_id', 'hostname', 'is_primary',
        'verification_token', 'verified_at', 'last_checked_at', 'last_error',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'verified_at' => 'datetime',
        'last_checked_at' => 'datetime',
    ];

    public function site()
    {
        return $this->belongsTo(Site::class);
    }

    public function isVerified(): bool
    {
        return null !== $this->verified_at;
    }

    public static function newToken(): string
    {
        return 'seatmap-verify-'.Str::lower(Str::random(32));
    }

    /** The DNS record an organiser has to publish. */
    public function expectedRecord(): array
    {
        return [
            'type' => 'TXT',
            'name' => '_seatmap-verify.'.$this->hostname,
            'value' => $this->verification_token,
        ];
    }

    /**
     * Hostnames are compared lowercased and without a trailing dot, because that is how a browser
     * sends them and how DNS writes them — and a mismatch here is a routing failure the organiser
     * cannot diagnose.
     */
    public static function normalise(string $hostname): string
    {
        return rtrim(Str::lower(trim($hostname)), '.');
    }
}
