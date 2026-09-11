<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Event extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'venue_id', 'series_id', 'seat_map_id', 'seat_map_version_id', 'public_id',
        'waiting_room', 'waiting_room_capacity', 'waiting_room_minutes',
        'name', 'description', 'image_url', 'category', 'status', 'starts_at', 'ends_at', 'timezone', 'currency',
        'hold_ttl_seconds', 'max_extends', 'max_seats_per_order', 'max_per_buyer',
        'checkout_min_seconds', 'refund_policy', 'settings',
        'booking_fee_kind', 'booking_fee_amount', 'booking_fee_percent', 'booking_fee_label',
        'tax_rate', 'tax_included', 'tax_label',
        'cancelled_at', 'cancellation_reason', 'rescheduled_from', 'rescheduled_at',
        'translations',
        'refunds', 'refund_window_hours', 'refund_keeps_fee',
        'exchanges', 'exchange_window_hours', 'exchange_fee_amount', 'resale', 'resale_pays',
        'presale_starts_at', 'on_sale_at',
        'accessible_sale', 'accessible_release_hours', 'ask_access_needs',
        'demand_pricing', 'price_floor', 'price_ceiling',
        'tier_presale', 'member_presale',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'rescheduled_from' => 'datetime',
        'rescheduled_at' => 'datetime',
        'settings' => 'array',
        'translations' => 'array',
        'ask_access_needs' => 'boolean',
        // Whether a Friend books before the general sale on this night.
        'member_presale' => 'boolean',
        'accessible_release_hours' => 'integer',
        'hold_ttl_seconds' => 'integer',
        'max_extends' => 'integer',
        'max_seats_per_order' => 'integer',
        'max_per_buyer' => 'integer',
        'checkout_min_seconds' => 'integer',
        'availability_version' => 'integer',
        // Pricing by how much is left. Off unless somebody asks for it: a price that moves on its
        // own is a decision a house makes deliberately, and some of them are forbidden to.
        'demand_pricing' => 'boolean',
        'price_floor' => 'integer',
        'price_ceiling' => 'integer',
        'booking_fee_amount' => 'integer',
        'booking_fee_percent' => 'integer',
        'tax_rate' => 'integer',
        'tax_included' => 'boolean',
        'refund_window_hours' => 'integer',
        'refund_keeps_fee' => 'boolean',
        'exchange_window_hours' => 'integer',
        'exchange_fee_amount' => 'integer',
        'resale' => 'boolean',
        'presale_starts_at' => 'datetime',
        'on_sale_at' => 'datetime',
        'waiting_room' => 'boolean',
        /*
         * A night being rehearsed rather than sold.
         *
         * Absent from `$fillable` deliberately: the ordinary event form must not be able to flip
         * it, because flipping it in either direction has conditions attached and a record to
         * write. {@see \App\Domain\Rehearsals\Rehearsals} is the only way in.
         */
        'is_rehearsal' => 'boolean',
        'waiting_room_capacity' => 'integer',
        'waiting_room_minutes' => 'integer',
    ];

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    public function venue()
    {
        return $this->belongsTo(Venue::class);
    }

    public function series()
    {
        return $this->belongsTo(EventSeries::class, 'series_id');
    }

    public function seatMap()
    {
        return $this->belongsTo(SeatMap::class);
    }

    public function seatMapVersion()
    {
        return $this->belongsTo(SeatMapVersion::class);
    }

    public function priceZones()
    {
        return $this->hasMany(EventPriceZone::class)->orderBy('sort_order');
    }

    public function seatOverrides()
    {
        return $this->hasMany(EventSeatOverride::class);
    }

    public function holds()
    {
        return $this->hasMany(Hold::class);
    }

    public function allocations()
    {
        return $this->hasMany(Allocation::class);
    }

    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }

    public function isSellable(): bool
    {
        return $this->status === 'published' && $this->seat_map_version_id !== null;
    }

    public function isCancelled(): bool
    {
        return 'cancelled' === $this->status;
    }

    /**
     * The event's name in one language, falling back to the one it was typed in.
     *
     * A missing translation is not an error and must never render as a blank: an organiser who has
     * written Persian and German has not thereby broken their French page. The original is the
     * fallback, and the original is always there — it is the column the event was created with.
     */
    public function nameFor(?string $locale = null): string
    {
        return $this->translated('name', $locale) ?: (string) $this->name;
    }

    /**
     * What this night is about, and — where it says nothing of its own — what the production is.
     *
     * A tour is the same show in twelve towns, and twelve copies of one paragraph is twelve places
     * to forget to change it. A night that has something of its own to say still wins: the last
     * performance of a run is sometimes a different evening from the first.
     */
    public function descriptionFor(?string $locale = null): ?string
    {
        return $this->translated('description', $locale)
            ?: ($this->description ?: $this->production()?->description);
    }

    /** The poster: this night's, or the production's. */
    public function posterFor(): ?string
    {
        return $this->image_url ?: $this->production()?->image_url;
    }

    /**
     * The run this night belongs to, loaded rather than lazily reached for.
     *
     * Lazy loading is off across the platform, and an undeclared read would be a silent null here
     * — which would show as a page that mysteriously has no poster. Eager-loaded callers pay
     * nothing; the rest pay one query for a row they are about to render.
     */
    private function production(): ?EventSeries
    {
        if (! $this->series_id) {
            return null;
        }

        $this->loadMissing('series');

        return $this->series;
    }

    /**
     * "Concert", "Theatre", "Club" — one word, and the one word on the page most likely to be
     * left in English on a Persian site because it looks like a system value rather than
     * something somebody typed. It is something somebody typed.
     */
    public function categoryFor(?string $locale = null): ?string
    {
        return $this->translated('category', $locale)
            ?: ($this->category ?: $this->production()?->category);
    }

    /** Which languages this event has actually been written in, original included. */
    public function writtenIn(): array
    {
        $written = [];

        foreach ((array) ($this->translations ?? []) as $locale => $fields) {
            if (trim((string) ($fields['name'] ?? '')) !== '') {
                $written[] = $locale;
            }
        }

        return $written;
    }

    private function translated(string $field, ?string $locale): ?string
    {
        $locale = \App\Support\Locale\Locales::normalise($locale ?: app()->getLocale());
        $value = $this->translations[$locale][$field] ?? null;

        return is_string($value) && '' !== trim($value) ? $value : null;
    }

    /**
     * Bump the availability cursor. Called whenever seat state changes so pollers can ask for
     * "what changed since N" rather than refetching the whole map.
     */
    public function bumpAvailabilityVersion(): int
    {
        $next = DB::table('events')
            ->where('id', $this->id)
            ->incrementEach(['availability_version' => 1]);

        $this->availability_version = (int) DB::table('events')
            ->where('id', $this->id)->value('availability_version');

        return $this->availability_version;
    }
}
