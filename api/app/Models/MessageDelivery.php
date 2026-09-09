<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * What happened to one message.
 *
 * `refused` and `unavailable` are kept apart on purpose: a provider that said "that is not a
 * mobile number" must never be retried, and a provider that could not be reached always should be.
 */
class MessageDelivery extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'kind', 'channel', 'recipient', 'locale', 'status', 'reference', 'reason',
        'preview', 'attempts', 'last_attempt_at', 'event_id', 'external_order_row_id',
    ];

    protected $casts = ['last_attempt_at' => 'datetime', 'attempts' => 'integer'];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }
}
