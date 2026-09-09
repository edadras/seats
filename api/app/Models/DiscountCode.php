<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A code that takes money off an order.
 *
 * The model answers "may this be used" and "what is it worth"; it does not answer "has it been
 * used up", because that question cannot be answered by reading a counter — see
 * App\Domain\Discounts\Discounts::redeem.
 */
class DiscountCode extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'event_id', 'code', 'description', 'kind', 'value', 'currency',
        'starts_at', 'ends_at', 'max_uses', 'min_seats', 'status', 'created_by',
    ];

    protected $casts = [
        'value' => 'integer',
        'max_uses' => 'integer',
        'used_count' => 'integer',
        'min_seats' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    /** What a buyer typed, turned into the one spelling this table stores. */
    public static function normalise(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function redemptions()
    {
        return $this->hasMany(DiscountRedemption::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by')->withoutGlobalScope('tenant');
    }

    /** Live now: switched on, started, not finished, and not used up. */
    public function isLive(?\DateTimeInterface $at = null): bool
    {
        $at = $at ?: now();

        return 'active' === $this->status
            && ! ($this->starts_at && $this->starts_at->greaterThan($at))
            && ! ($this->ends_at && $this->ends_at->lessThanOrEqualTo($at))
            && ! $this->isUsedUp();
    }

    public function isUsedUp(): bool
    {
        return null !== $this->max_uses && $this->used_count >= $this->max_uses;
    }

    /**
     * What this code takes off a subtotal.
     *
     * Never more than the subtotal: a €20 code against a €12 order is a free ticket, not a €8
     * refund. Percentages round down, so the discount is never a penny more than promised.
     */
    public function amountOff(int $subtotal): int
    {
        $off = 'percent' === $this->kind
            ? intdiv($subtotal * $this->value, 100)
            : $this->value;

        return max(0, min($subtotal, $off));
    }
}
