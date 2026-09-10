<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A seat its owner has offered back to the public at what they paid for it.
 *
 * The listing moves no inventory. The seat stays allocated to the person who bought it — they are
 * still going if nobody takes it — and is merely *offered* while the listing is open. Everything
 * happens at the moment somebody else buys it, in one transaction: see App\Domain\Resale\Resales.
 */
class ResaleListing extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'event_id', 'allocation_id', 'seller_email', 'seller_name',
        'amount', 'currency', 'state', 'listed_at', 'settled_at', 'voucher_id',
    ];

    protected $casts = [
        'amount' => 'integer',
        'listed_at' => 'datetime',
        'settled_at' => 'datetime',
    ];

    public function allocation()
    {
        return $this->belongsTo(Allocation::class);
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function isOpen(): bool
    {
        return 'open' === $this->state;
    }
}
