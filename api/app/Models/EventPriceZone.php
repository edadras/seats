<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EventPriceZone extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = ['tenant_id', 'event_id', 'key', 'name', 'amount', 'color', 'sort_order'];

    /** Amounts are always minor units as integers — never a float, never a formatted string. */
    protected $casts = ['amount' => 'integer'];
}
