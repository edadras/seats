<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Something an organiser wants to say to the people who bought from them.
 *
 * It holds the instruction. The messages themselves are ordinary deliveries, linked back here, so
 * "did it arrive" is answered in the one place that question is ever answered.
 */
class Announcement extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'event_id', 'audience', 'channels', 'locale', 'subject', 'body',
        'status', 'recipients', 'created_by', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'channels' => 'array',
        'recipients' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function deliveries()
    {
        return $this->hasMany(MessageDelivery::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by')->withoutGlobalScope('tenant');
    }
}
