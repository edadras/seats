<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One subscriber's first refusal on their own chairs.
 *
 * An offer, never a booking: nothing is charged and no seat is allocated until they accept and pay
 * like anybody else. What it does while it stands is keep those seats out of everybody else's
 * reach — see the availability SQL, which asks this table rather than being told by it.
 */
class RenewalOffer extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'round_id', 'email', 'name', 'state',
        'invited_at', 'responded_at', 'season_booking_id',
    ];

    protected $casts = [
        'invited_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    public function round()
    {
        return $this->belongsTo(RenewalRound::class, 'round_id');
    }

    public function seats()
    {
        return $this->hasMany(RenewalOfferSeat::class, 'offer_id');
    }

    public function isOpen(): bool
    {
        return 'offered' === $this->state;
    }
}
