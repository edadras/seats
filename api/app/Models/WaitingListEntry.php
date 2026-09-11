<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One person waiting for a seat on one night.
 *
 * `status` is the whole story, and it moves: waiting, told a seat is free, bought one, gone, or
 * quiet. A person whose turn runs out goes back to waiting rather than being dropped — they did not
 * do anything wrong by being asleep at three in the morning — and comes round again on the next
 * release, behind anybody who has not had a turn yet.
 *
 * `lapsed` is where that stops. After a few unanswered turns the platform stops writing to them:
 * the row stays and the organiser can see it, but an email every two hours until the doors open is
 * not a waiting list.
 */
class WaitingListEntry extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'event_id', 'name', 'email', 'phone', 'quantity', 'locale',
        'status', 'token', 'notified_at', 'claim_expires_at', 'left_at',
        'times_told', 'converted_at', 'lapsed_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'times_told' => 'integer',
        'notified_at' => 'datetime',
        'claim_expires_at' => 'datetime',
        'left_at' => 'datetime',
        'converted_at' => 'datetime',
        'lapsed_at' => 'datetime',
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
