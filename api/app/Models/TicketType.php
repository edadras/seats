<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Who a ticket is for: full price, child, student, senior, member.
 *
 * The price is a function of the seat's own price, never a number of its own — except for `fixed`,
 * which is there for the one case that genuinely is a flat rate whatever the seat (a companion
 * ticket, a schools rate).
 */
class TicketType extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'event_id', 'name', 'description', 'kind', 'value',
        'is_default', 'min_per_order', 'max_per_order', 'proof_note', 'position', 'status',
    ];

    protected $casts = [
        'value' => 'integer',
        'is_default' => 'boolean',
        'min_per_order' => 'integer',
        'max_per_order' => 'integer',
        'position' => 'integer',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function isSellable(): bool
    {
        return 'active' === $this->status;
    }

    /**
     * What this type pays for a seat priced at `$base`.
     *
     * Never below zero and never above the seat's own price for the three discounting kinds: a
     * "20% off" that came out higher than full price would be a bug nobody would notice until an
     * angry buyer noticed it. `fixed` is allowed above the base, because a flat rate is a stated
     * price and not a discount.
     */
    public function priceFrom(int $base): int
    {
        $amount = match ($this->kind) {
            'percent_off' => $base - intdiv($base * max(0, min(100, $this->value)), 100),
            'amount_off' => $base - max(0, $this->value),
            'fixed' => max(0, $this->value),
            default => $base,
        };

        return 'fixed' === $this->kind ? $amount : max(0, min($base, $amount));
    }
}
