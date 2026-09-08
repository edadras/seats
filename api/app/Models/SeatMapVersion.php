<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A snapshot of a seat map's layout.
 *
 * Once published, a version is immutable: orders sold against it must keep resolving to the exact
 * geometry and seat set they were sold with. The `saving` guard below is the enforcement — without
 * it, an innocuous-looking `$version->update()` somewhere would quietly rewrite history.
 */
class SeatMapVersion extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'seat_map_id', 'version', 'status', 'geometry',
        'seat_count', 'checksum', 'notes', 'published_at', 'published_by',
    ];

    protected $casts = ['geometry' => 'array', 'published_at' => 'datetime', 'seat_count' => 'integer'];

    protected static function booted(): void
    {
        static::saving(function (self $version) {
            // Allow the transition draft -> published, but nothing after it.
            if ($version->exists && $version->getOriginal('status') === 'published') {
                throw new \RuntimeException(
                    "Seat map version {$version->id} is published and immutable. Create a new draft instead."
                );
            }
        });
    }

    public function seatMap()
    {
        return $this->belongsTo(SeatMap::class);
    }

    public function placements()
    {
        return $this->hasMany(SeatPlacement::class);
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }
}
