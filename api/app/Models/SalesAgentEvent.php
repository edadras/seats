<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One night an agent is allowed to sell.
 *
 * A list rather than a level, because "can sell events" is not a permission anybody actually
 * grants: a bureau is given the summer festival and not the members' evening.
 */
class SalesAgentEvent extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'sales_agent_id', 'event_id'];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }
}
