<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One person in the queue outside a sale.
 *
 * `lobby` is before the doors open and has no place, because before the doors open nobody has one —
 * refreshing for an hour beforehand must buy nothing. `queued` has a place and is waiting for it to
 * come up. `admitted` is inside, on a lease that runs out.
 */
class QueueTicket extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    public const STATUSES = ['lobby', 'queued', 'admitted', 'expired', 'left'];

    protected $fillable = [
        'tenant_id', 'event_id', 'token', 'session_id', 'ip',
        'status', 'place', 'joined_at', 'admitted_at', 'expires_at', 'left_at',
    ];

    protected $casts = [
        'place' => 'integer',
        'joined_at' => 'datetime',
        'admitted_at' => 'datetime',
        'expires_at' => 'datetime',
        'left_at' => 'datetime',
    ];

    /** In a cookie, never in a URL: a place that could be pasted into a message could be sold. */
    public static function newToken(): string
    {
        return Str::lower(Str::random(48));
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    /** Inside, and the lease has not run out. The only state that may buy anything. */
    public function isAdmitted(?\DateTimeInterface $at = null): bool
    {
        return 'admitted' === $this->status
            && $this->expires_at
            && $this->expires_at->greaterThan($at ?: now());
    }

    public function isWaiting(): bool
    {
        return in_array($this->status, ['lobby', 'queued'], true);
    }
}
