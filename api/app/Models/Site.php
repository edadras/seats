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
        'tenant_id', 'api_client_id', 'name', 'theme_key', 'site_theme_id', 'locale', 'locales', 'timezone',
        'currency', 'brand', 'status', 'google_signin', 'published_at',
        'invoices_enabled', 'legal_name', 'tax_number', 'billing_address',
        'invoice_footer', 'invoice_prefix', 'measurement',
    ];

    protected $casts = [
        'brand' => 'array',
        'locales' => 'array',
        'google_signin' => 'boolean',
        'published_at' => 'datetime',
        'invoices_enabled' => 'boolean',
        // {provider => id}. {@see \App\Domain\Sites\Measurement}.
        'measurement' => 'array',
    ];

    /**
     * The languages this site is published in, its own always among them.
     *
     * The switcher in the footer used to offer all six the platform speaks, whatever the organiser
     * had actually written — so a visitor could choose Italian and be handed a Persian page with
     * English furniture. This is the honest list, and the only one offered.
     *
     * @return list<string>
     */
    public function publishedLocales(): array
    {
        $own = \App\Support\Locale\Locales::normalise($this->locale) ?? \App\Support\Locale\Locales::FALLBACK;

        $offered = array_values(array_filter(array_map(
            fn ($code) => \App\Support\Locale\Locales::normalise($code),
            (array) ($this->locales ?? []),
        )));

        // The site's own language is not optional: it is what every untranslated word is in.
        return array_values(array_unique(array_merge([$own], $offered)));
    }

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

    /**
     * Whether this site offers "sign in with Google" to its buyers.
     *
     * Both halves have to be true: the organiser turned it on, and the platform has credentials to
     * turn it on *with*. A button that leads to a Google error page is worse than no button.
     */
    public function offersSignIn(): bool
    {
        return (bool) $this->google_signin
            && app(\App\Domain\Sites\Auth\GoogleIdentity::class)->configured();
    }

    /**
     * Whether this shop can issue an invoice at all.
     *
     * Switched on *and* filled in: an invoice with no issuing entity and no tax number is not a
     * document anybody's accounts department will take, so offering the button would be a promise
     * this site cannot keep.
     */
    public function offersInvoices(): bool
    {
        return (bool) $this->invoices_enabled
            && '' !== trim((string) ($this->legal_name ?: $this->name))
            && '' !== trim((string) $this->billing_address);
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

                return $href
                    ? ['label' => $item->labelFor(), 'href' => $href, 'new_tab' => $item->new_tab]
                    : null;
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
