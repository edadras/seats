<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One time a code opened a door.
 *
 * Kept rather than counted, because a cap has to survive two buyers pressing at once, and because
 * "who used it" is the first thing an organiser asks about a code they gave to a radio station.
 */
class AccessCodeUse extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'access_code_uses';

    protected $fillable = [
        'tenant_id', 'access_code_id', 'hold_id', 'external_order_row_id', 'seats', 'released_at',
    ];

    protected $casts = [
        'seats' => 'integer',
        'released_at' => 'datetime',
    ];

    public function code()
    {
        return $this->belongsTo(AccessCode::class, 'access_code_id');
    }

    public function order()
    {
        return $this->belongsTo(ExternalOrder::class, 'external_order_row_id');
    }
}
