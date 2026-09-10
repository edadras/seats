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
        'hold_ttl_seconds', 'max_extends', 'max_seats_per_order', 'refund_policy', 'settings',
        'booking_fee_kind', 'booking_fee_amount', 'booking_fee_percent', 'booking_fee_label',
        'tax_rate', 'tax_included', 'tax_label',
        'cancelled_at', 'cancellation_reason', 'rescheduled_from', 'rescheduled_at',
        'translations',
        'refunds', 'refund_window_hours', 'refund_keeps_fee',
        'presale_starts_at', 'on_sale_at',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'rescheduled_from' => 'datetime',
        'rescheduled_at' => 'datetime',
        'settings' => 'array',
        'translations' => 'array',
        'hold_ttl_seconds' => 'integer',
        'max_extends' => 'integer',
        'max_seats_per_order' => 'integer',
        'availability_version' => 'integer',
        'booking_fee_amount' => 'integer',
        'booking_fee_percent' => 'integer',
        'tax_rate' => 'integer',
        'tax_included' => 'boolean',
        'refund_window_hours' => 'integer',
        'refund_keeps_fee' => 'boolean',
        'presale_starts_at' => 'datetime',
        'on_sale_at' => 'datetime',
        'waiting_room' => 'boolean',
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

    public function descriptionFor(?string $locale = null): ?string
    {
        return $this->translated('description', $locale) ?: $this->description;
    }

    /**
     * "Concert", "Theatre", "Club" — one word, and the one word on the page most likely to be
     * left in English on a Persian site because it looks like a system value rather than
     * something somebody typed. It is something somebody typed.
     */
    public function categoryFor(?string $locale = null): ?string
    {
        return $this->translated('category', $locale) ?: $this->category;
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
