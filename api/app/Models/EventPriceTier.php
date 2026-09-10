<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A window of time and what a ticket costs inside it.
 *
 * Nothing here is a price. A tier moves the zone prices — up for the last week, down for the first
 * month — because an organiser prices their room once and then decides when it is cheap.
 */
class EventPriceTier extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'event_id', 'name', 'starts_at', 'ends_at', 'kind', 'value', 'sort_order',
    ];

    protected $casts = [
        'starts_at' => 'immutable_datetime',
        'ends_at' => 'immutable_datetime',
        'value' => 'integer',
        'sort_order' => 'integer',
    ];

    /** Is this the tier in force at that moment? Both ends are open unless a date says otherwise. */
    public function coversNow(?\DateTimeInterface $at = null): bool
    {
        $moment = $at ? \Illuminate\Support\Carbon::instance(\Illuminate\Support\Carbon::parse($at)) : now();

        if ($this->starts_at && $moment->lessThan($this->starts_at)) {
            return false;
        }

        // Exclusive at the far end, so a tier ending at nine and one starting at nine do not both
        // claim nine o'clock — the second one has it.
        return ! ($this->ends_at && $moment->greaterThanOrEqualTo($this->ends_at));
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }
}
