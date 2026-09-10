<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EventSeatOverride extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = ['tenant_id', 'event_id', 'seat_id', 'blocked', 'held_for', 'amount', 'zone_key', 'note'];

    protected $casts = ['blocked' => 'boolean', 'amount' => 'integer'];
}
