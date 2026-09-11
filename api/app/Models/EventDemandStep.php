<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One rung on the demand ladder: from this much sold, this much on the price.
 *
 * `sold_from` is a floor rather than a band. The step in force is the highest rung the night has
 * reached, so a ladder of 0, 50, 80 and 95 covers every percentage without anybody having to make
 * the edges meet — and an edge typed one out is a percentage with no price at all, which is the
 * failure bands invite.
 */
class EventDemandStep extends Model
{
    use BelongsToTenant, HasUuids;

    public const KINDS = ['percent', 'amount'];

    protected $fillable = [
        'tenant_id', 'event_id', 'name', 'sold_from', 'kind', 'value', 'sort_order',
    ];

    protected $casts = [
        'sold_from' => 'integer',
        'value' => 'integer',
        'sort_order' => 'integer',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }
}
