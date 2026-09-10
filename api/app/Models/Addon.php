<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A thing sold beside a ticket: a programme, a glass of wine, a parking space.
 *
 * It is not an admission. Nothing here produces a ticket, an allocation, or a row on a door list —
 * the organiser reads what was bought off the booking and hands it over at the counter.
 *
 * The model answers what it costs and how many one buyer may take. It does not answer "is there
 * any left": that is a sum against a limit and cannot be read off a column — see
 * App\Domain\Addons\Addons::remaining.
 */
class Addon extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    /** Whether the number is chosen by the buyer, or follows the tickets. */
    public const PER = ['order', 'ticket'];

    protected $fillable = [
        'tenant_id', 'event_id', 'name', 'description', 'price', 'currency',
        'stock', 'max_per_order', 'per', 'position', 'visible',
    ];

    protected $casts = [
        'price' => 'integer',
        'stock' => 'integer',
        'max_per_order' => 'integer',
        'position' => 'integer',
        'visible' => 'boolean',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function lines()
    {
        return $this->hasMany(OrderAddon::class);
    }

    /** Whether this add-on has anything to say about one event. */
    public function covers(Event $event): bool
    {
        return null === $this->event_id || $this->event_id === $event->id;
    }

    /** One per ticket, priced per ticket and not chosen — a compulsory charge that is a thing. */
    public function isPerTicket(): bool
    {
        return 'ticket' === $this->per;
    }
}
