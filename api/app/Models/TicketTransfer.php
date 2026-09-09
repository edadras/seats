<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One ticket, handed from one person to another.
 *
 * A record rather than a mechanism: the ticket is reissued at the moment of transfer, so this is
 * here to answer "who did this start with" — at the window, and when somebody says they never
 * received it.
 */
class TicketTransfer extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'ticket_id', 'external_order_row_id',
        'from_email', 'from_name', 'to_email', 'to_name', 'transferred_at',
    ];

    protected $casts = ['transferred_at' => 'datetime'];

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function order()
    {
        return $this->belongsTo(ExternalOrder::class, 'external_order_row_id');
    }
}
