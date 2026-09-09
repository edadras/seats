<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One arrival window of a timed-entry event.
 *
 * `capacity` is how many people may come in during this window. Null is a window that limits
 * nothing: some runs stagger arrivals for the queue's sake and have one hall behind it.
 */
class EntrySlot extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'event_id', 'label', 'starts_at', 'ends_at', 'capacity', 'status',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'capacity' => 'integer',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function isOpen(): bool
    {
        return 'open' === $this->status;
    }
}
