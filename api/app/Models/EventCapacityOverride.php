<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-event adjustments to a capacity object: a price, a block, or a reduced house for one night.
 */
class EventCapacityOverride extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = ['tenant_id', 'event_id', 'capacity_object_id', 'blocked', 'places', 'amount', 'zone_key', 'note'];

    protected $casts = ['blocked' => 'boolean', 'places' => 'integer', 'amount' => 'integer'];
}
