<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Where a capacity object sits in one particular map version. */
class CapacityPlacement extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = ['tenant_id', 'seat_map_version_id', 'capacity_object_id', 'floor_key', 'geometry'];

    protected $casts = ['geometry' => 'array'];

    public function capacityObject()
    {
        return $this->belongsTo(CapacityObject::class);
    }
}
