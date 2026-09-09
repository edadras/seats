<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One person waiting for a seat on one night.
 *
 * `status` is the whole story: waiting, told a seat is free, bought one, or gone. A person whose
 * turn ran out goes back to waiting rather than being dropped — they did not do anything wrong by
 * being asleep at three in the morning.
 */
class WaitingListEntry extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'event_id', 'name', 'email', 'phone', 'quantity', 'locale',
        'status', 'token', 'notified_at', 'claim_expires_at', 'left_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'notified_at' => 'datetime',
        'claim_expires_at' => 'datetime',
        'left_at' => 'datetime',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    /** Long enough that it cannot be guessed, short enough to survive an email client's line wrap. */
    public static function newToken(): string
    {
        return Str::random(48);
    }

    /** Their turn is live: told, and not yet run out. */
    public function isClaiming(?\DateTimeInterface $at = null): bool
    {
        return 'notified' === $this->status
            && $this->claim_expires_at
            && $this->claim_expires_at->greaterThan($at ?: now());
    }
}
