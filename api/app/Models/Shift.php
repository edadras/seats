<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One person, one drawer, between two times.
 *
 * What the till *should* hold is never a column while the shift is open — see
 * App\Domain\BoxOffice\Tills, which counts it. The two amounts stored here are written once, at
 * the close, because a count of the cash in a drawer is a physical observation and the expectation
 * it was compared against is part of the same photograph.
 */
class Shift extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'user_id', 'event_id', 'currency', 'opening_float',
        'opened_at', 'closed_at', 'counted_cash', 'expected_cash', 'note',
    ];

    protected $casts = [
        'opening_float' => 'integer',
        'counted_cash' => 'integer',
        'expected_cash' => 'integer',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function movements()
    {
        return $this->hasMany(ShiftMovement::class);
    }

    public function orders()
    {
        return $this->hasMany(ExternalOrder::class);
    }

    public function isOpen(): bool
    {
        return null === $this->closed_at;
    }
}
