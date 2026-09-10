<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A production: one show, however many nights and however many towns.
 *
 * It carries what an audience recognises — the name, the picture and the sentence that says what
 * this is — and nothing else. Everything that decides what a night costs and where people sit
 * belongs to the night, so one performance can be repriced, sold out or cancelled without touching
 * the rest of the run, and a hall in Glasgow can have a different chart from the one in Leeds.
 */
class EventSeries extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'event_series';

    protected $fillable = [
        'tenant_id', 'name', 'slug', 'description', 'image_url', 'category',
    ];

    public function events()
    {
        return $this->hasMany(Event::class, 'series_id');
    }

    /** A readable, stable address for the run. Falls back to a random suffix on a collision. */
    public static function slugFor(string $name, ?string $tenantId = null): string
    {
        $base = Str::slug($name) ?: 'series';
        $slug = $base;
        $attempt = 1;

        while (self::where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$attempt);
        }

        return mb_substr($slug, 0, 120);
    }
}
