<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Where a seat sits in one particular map version. */
class SeatPlacement extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'seat_map_version_id', 'seat_id',
        'x', 'y', 'rotation', 'shape', 'zone_key',
    ];

    protected $casts = ['x' => 'float', 'y' => 'float', 'rotation' => 'float'];

    public function seat()
    {
        return $this->belongsTo(Seat::class);
    }
}
