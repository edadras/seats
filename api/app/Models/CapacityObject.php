<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A place sold by quantity rather than by name: a general admission area, a booth, or a table
 * booked whole.
 *
 * Like a seat, its id is stable for the life of the map and is never reused, so a sale made two
 * versions ago still points at the same standing area. Unlike a seat, exclusivity is a running
 * total against `places` rather than one row per person.
 */
class CapacityObject extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'seat_map_id', 'section_id', 'key', 'label',
        'kind', 'capacity_type', 'places', 'attributes',
    ];

    protected $casts = ['places' => 'integer', 'attributes' => 'array'];

    public function placements()
    {
        return $this->hasMany(CapacityPlacement::class);
    }

    /** True when a buyer takes the whole thing rather than a share of it. */
    public function isSoldWhole(): bool
    {
        return $this->capacity_type === 'fixed';
    }
}
