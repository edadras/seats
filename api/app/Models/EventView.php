<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A day's worth of looking at one event, from one kind of place.
 *
 * Never written through this model in a request — see App\Domain\Insights\SalesPace::record, which
 * raises the count with a single upsert so two people opening the page at the same moment cannot
 * lose one of the two. This is here for reading, and for the tenant scope reading needs.
 */
class EventView extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = ['tenant_id', 'event_id', 'day', 'source', 'views'];

    protected $casts = ['day' => 'date', 'views' => 'integer'];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }
}
