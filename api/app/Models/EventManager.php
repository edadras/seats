<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One night, in one programme manager's hands.
 *
 * The whole of what a manager may reach is derived from these rows: no grant, no night — and every
 * refusal that follows from that is a "cannot be found" rather than a "not allowed", because which
 * nights an organiser is running is not a manager's business either.
 */
class EventManager extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'user_id', 'event_id', 'granted_by'];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
