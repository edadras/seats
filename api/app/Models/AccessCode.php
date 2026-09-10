<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A code that opens a door, as against a discount code, which changes a price.
 *
 * The model answers "is this switched on, started, and not finished" and "what does it open". It
 * does not answer "has it been used up": that is a sum against a limit and cannot be read off a
 * counter — see App\Domain\Access\AccessCodes::spend.
 */
class AccessCode extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    /** Everything before general sale, and everything at all. */
    public const OPENS = ['presale', 'always'];

    protected $fillable = [
        'tenant_id', 'event_id', 'code', 'label', 'opens', 'ticket_type_ids',
        'starts_at', 'ends_at', 'max_uses', 'max_seats', 'status', 'created_by',
    ];

    protected $casts = [
        'ticket_type_ids' => 'array',
        'max_uses' => 'integer',
        'max_seats' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    /** What a buyer typed, turned into the one spelling this table stores. */
    public static function normalise(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    /**
     * A code somebody can read out over a telephone.
     *
     * No O against 0, no I against 1: this is printed on a flyer and typed by a person who is not
     * looking at the flyer while they type it.
     */
    public static function suggest(int $length = 8): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function uses()
    {
        return $this->hasMany(AccessCodeUse::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by')->withoutGlobalScope('tenant');
    }

    /** Switched on, started, and not finished. Whether it is used up is somebody else's question. */
    public function isLive(?\DateTimeInterface $at = null): bool
    {
        $at = $at ?: now();

        return 'active' === $this->status
            && ! ($this->starts_at && $this->starts_at->greaterThan($at))
            && ! ($this->ends_at && $this->ends_at->lessThanOrEqualTo($at));
    }

    /** Whether this code has anything to say about one event. */
    public function covers(Event $event): bool
    {
        return null === $this->event_id || $this->event_id === $event->id;
    }

    /**
     * Whether holding this unlocks one ticket type.
     *
     * An empty list means the code says nothing about ticket types — it is a presale code, not a
     * members' price — and a type that is not locked is open to everybody anyway.
     */
    public function unlocksType(string $ticketTypeId): bool
    {
        return in_array($ticketTypeId, (array) ($this->ticket_type_ids ?? []), true);
    }
}
