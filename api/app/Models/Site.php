<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An organiser's own event site, rendered by this application.
 *
 * See `docs/adr/0003-hosted-event-sites.md`. A site owns its pages, its menus and its domains, and
 * sells through an ordinary `api_clients` row so that the hosted checkout reaches exactly the same
 * order lifecycle a WooCommerce shop does.
 */
class Site extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'api_client_id', 'name', 'theme_key', 'site_theme_id', 'locale', 'timezone',
        'currency', 'brand', 'status', 'published_at',
    ];

    protected $casts = [
        'brand' => 'array',
        'published_at' => 'datetime',
    ];

    /**
     * The theme this organiser wrote, when the site wears one.
     *
     * Null means a first-party theme, named by `theme_key`. The relation is nulled rather than
     * blocked on delete, so a site is never left rendering with nothing.
     */
    public function customTheme()
    {
        return $this->belongsTo(SiteTheme::class, 'site_theme_id');
    }

    public function domains()
    {
        return $this->hasMany(SiteDomain::class);
    }

    public function primaryDomain()
    {
        return $this->hasOne(SiteDomain::class)->where('is_primary', true);
    }

    public function pages()
    {
        return $this->hasMany(SitePage::class)->orderBy('position');
    }

    public function menus()
    {
        return $this->hasMany(SiteMenu::class);
    }

    public function apiClient()
    {
        return $this->belongsTo(ApiClient::class);
    }

    public function isLive(): bool
    {
        return 'live' === $this->status;
    }

    /** The hostname canonical URLs and emails are built from. */
    public function canonicalHost(): ?string
    {
        $primary = $this->relationLoaded('primaryDomain')
            ? $this->primaryDomain
            : $this->domains()->where('is_primary', true)->first();

        return $primary?->hostname;
    }

    /**
     * A menu, flattened to what a template needs: label, href, target.
     *
     * On the model rather than in a controller because checkout and the confirmation page need the
     * same navigation as a content page, and a site whose header disappears at checkout looks
     * broken at exactly the moment a buyer is deciding whether to trust it.
     */
    public function menuFor(string $key): array
    {
        // Items carry their targets: a menu is rendered on every page, and resolving each link's
        // page or event one query at a time is the N+1 that turns a nav bar into a page load.
        $menu = $this->relationLoaded('menus')
            ? $this->menus->firstWhere('key', $key)
            : $this->menus()->with('items.page', 'items.event')->where('key', $key)->first();

        if ($menu && ! $menu->relationLoaded('items')) {
            $menu->load('items.page', 'items.event');
        }

        if ($menu) {
            $menu->items->loadMissing('page', 'event');
        }

        if (! $menu) {
            return [];
        }

        return $menu->items
            ->whereNull('parent_id')
            ->map(function (SiteMenuItem $item) {
                $href = match ($item->target_type) {
                    'page' => $item->page?->path(),
                    'event' => $item->event ? '/events/'.$item->event->public_id : null,
                    default => $item->url,
                };

                return $href ? ['label' => $item->label, 'href' => $href, 'new_tab' => $item->new_tab] : null;
            })
            ->filter()
            ->values()
            ->all();
    }

    public function url(string $path = '/'): string
    {
        $host = $this->canonicalHost();

        if (! $host) {
            return $path;
        }

        return rtrim(config('seatmap.sites.scheme', 'https').'://'.$host, '/').'/'.ltrim($path, '/');
    }
}
