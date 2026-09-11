<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A photograph of what the stage looks like from one section.
 *
 * Belongs to the map rather than to a version of it: a picture is not part of the seating, and
 * changing one must not mean republishing a chart.
 */
class SeatView extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = ['tenant_id', 'seat_map_id', 'section_key', 'url', 'caption'];

    public function seatMap()
    {
        return $this->belongsTo(SeatMap::class);
    }
}
