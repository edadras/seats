<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A physical seat. Its id is stable for the life of the map and is never reused, so an allocation
 * made two map versions ago still points at the same chair.
 *
 * Note what is *not* here: any notion of availability. That is derived per event from overrides,
 * live holds and allocations (ADR-0002).
 */
class Seat extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'seat_map_id', 'section_id', 'seat_row_id',
        'key', 'label', 'accessible', 'attributes',
    ];

    protected $casts = ['accessible' => 'boolean', 'attributes' => 'array'];

    public function section()
    {
        return $this->belongsTo(Section::class);
    }

    public function row()
    {
        return $this->belongsTo(SeatRow::class, 'seat_row_id');
    }

    public function placements()
    {
        return $this->hasMany(SeatPlacement::class);
    }
}
