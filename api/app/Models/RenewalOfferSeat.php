<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** One chair inside one subscriber's offer — the same chair on every night of the run. */
class RenewalOfferSeat extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = ['tenant_id', 'offer_id', 'round_id', 'seat_id'];

    public function offer()
    {
        return $this->belongsTo(RenewalOffer::class, 'offer_id');
    }

    public function seat()
    {
        return $this->belongsTo(Seat::class);
    }
}
