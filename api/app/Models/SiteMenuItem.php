<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One link in a menu.
 *
 * It points at exactly one thing — a page, an event, or a URL — and a check constraint enforces
 * that, because a link with two targets is ambiguous and one with none is dead.
 */
class SiteMenuItem extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'site_menu_id', 'parent_id', 'label', 'translations', 'target_type',
        'site_page_id', 'event_id', 'url', 'new_tab', 'position',
    ];

    protected $casts = ['new_tab' => 'boolean', 'translations' => 'array'];

    /**
     * The label in the reader's language, or the one it was written in.
     *
     * The words in a header are the first thing a visitor reads and were the last thing on a
     * hosted site that could not be said in their language.
     */
    public function labelFor(?string $locale = null): string
    {
        $locale = \App\Support\Locale\Locales::normalise($locale ?: app()->getLocale());
        $value = $this->translations[$locale] ?? null;

        return is_string($value) && '' !== trim($value) ? $value : (string) $this->label;
    }

    public function menu()
    {
        return $this->belongsTo(SiteMenu::class, 'site_menu_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    public function page()
    {
        return $this->belongsTo(SitePage::class, 'site_page_id');
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }
}
