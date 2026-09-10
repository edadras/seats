<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One season being offered to last season's subscribers, with a date on it.
 *
 * The date is the whole of the promise: until it passes, these chairs belong to the people who sat
 * in them; after it, they are on general sale. Nothing sweeps to make that true — availability
 * reads the deadline live, so a round nobody closed still stops holding seats on time.
 */
class RenewalRound extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'from_series_id', 'to_series_id', 'season_pass_id',
        'name', 'deadline', 'state', 'closed_at', 'created_by',
    ];

    protected $casts = [
        'deadline' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function offers()
    {
        return $this->hasMany(RenewalOffer::class, 'round_id');
    }

    public function pass()
    {
        return $this->belongsTo(SeasonPass::class, 'season_pass_id');
    }

    public function fromSeries()
    {
        return $this->belongsTo(EventSeries::class, 'from_series_id');
    }

    public function toSeries()
    {
        return $this->belongsTo(EventSeries::class, 'to_series_id');
    }

    /** Open, and not yet past the date it promised. */
    public function isLive(): bool
    {
        return 'open' === $this->state && $this->deadline && $this->deadline->isFuture();
    }
}
