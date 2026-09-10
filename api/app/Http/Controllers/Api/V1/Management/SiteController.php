<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Sites\Blocks;
use App\Domain\Sites\Payments\GatewayRegistry;
use App\Domain\Sites\Auth\GoogleIdentity;
use App\Domain\Sites\SiteProvisioner;
use App\Domain\Sites\SiteResolver;
use App\Domain\Sites\Themes;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Jobs\VerifySiteDomain;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteMenu;
use App\Models\SiteMenuItem;
use App\Models\SitePage;
use App\Models\SiteTheme;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The panel's side of a hosted site: the site, its pages, its menus and its domains.
 *
 * Everything an organiser types arrives here and goes through App\Domain\Sites\Blocks on the way
 * in. That is the boundary — rendering re-validates nothing, so anything that gets past this
 * controller is treated as safe to put on a public page.
 */
class SiteController extends Controller
{
    public function __construct(
        private readonly SiteProvisioner $provisioner,
        private readonly GatewayRegistry $gateways,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request)
    {
        $this->authorize($request, 'sites.view');

        $sites = Site::with('domains')->orderBy('name')->get();

        return response()->json(['data' => $sites->map(fn (Site $site) => $this->present($site))]);
    }

    public function store(Request $request)
    {
        $this->authorize($request, 'sites.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'theme_key' => ['sometimes', 'string', 'max:40'],
            'locale' => ['sometimes', 'string', 'max:12'],
            'timezone' => ['sometimes', 'string', 'timezone'],
            'currency' => ['sometimes', 'string', 'size:3'],
        ]);

        $site = $this->provisioner->create($data['name'], $data);

        $this->audit->record('site.created', $site, ['name' => $site->name]);

        return response()->json($this->present($site, withPages: true), 201);
    }

    public function show(Request $request, Site $site)
    {
        $this->authorize($request, 'sites.view');

        return response()->json($this->present($site->load(['domains', 'pages', 'menus.items']), withPages: true));
    }

    public function update(Request $request, Site $site)
    {
        $this->authorize($request, 'sites.manage');

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'theme_key' => ['sometimes', 'string', 'max:40'],
            // Null puts the site back into one of ours. The id is looked up through this tenant's
            // own themes, so another account's theme cannot be worn by guessing an id.
            'site_theme_id' => ['sometimes', 'nullable', 'uuid'],
            'locale' => ['sometimes', 'string', 'max:12'],
            // Which languages this site is published in. The site's own is added back whatever
            // arrives — it is what every untranslated word on the site is written in.
            'locales' => ['sometimes', 'array', 'max:12'],
            'locales.*' => ['string', 'max:12'],
            'timezone' => ['sometimes', 'string', 'timezone'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'status' => ['sometimes', 'in:draft,live'],
            'brand' => ['sometimes', 'array'],
            'google_signin' => ['sometimes', 'boolean'],
            // Who is issuing invoices from this shop, and under what tax number. Switched on and
            // filled in, or not offered at all — see Site::offersInvoices.
            'invoices_enabled' => ['sometimes', 'boolean'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'tax_number' => ['sometimes', 'nullable', 'string', 'max:60'],
            'billing_address' => ['sometimes', 'nullable', 'string', 'max:600'],
            'invoice_footer' => ['sometimes', 'nullable', 'string', 'max:300'],
            'invoice_prefix' => ['sometimes', 'nullable', 'string', 'max:12', 'regex:/^[A-Za-z0-9-]*$/'],
        ]);

        // Offered only where the platform has credentials to offer it with. A switch that turns on
        // a button leading to a Google error page is worse than no switch.
        if (! empty($data['google_signin']) && ! app(GoogleIdentity::class)->configured()) {
            throw ApiException::unprocessable(
                'signin_not_configured',
                'This platform has not been given Google credentials, so buyers cannot sign in yet.'
            );
        }

        if (isset($data['theme_key']) && ! Themes::exists($data['theme_key'])) {
            throw ApiException::unprocessable('unknown_theme', 'That theme does not exist.');
        }

        if (! empty($data['site_theme_id']) && ! SiteTheme::whereKey($data['site_theme_id'])->exists()) {
            throw ApiException::unprocessable('unknown_theme', 'That theme does not exist.');
        }

        if (array_key_exists('brand', $data)) {
            $data['brand'] = $this->brand($data['brand'], $site);
        }

        /*
         * An unverified address may build anything and publish nothing.
         *
         * That is the whole restriction on a self-served account, and it is the right one: the
         * thing an unverified address could be used for is putting content on the public internet
         * under somebody else's name.
         */
        if (($data['status'] ?? null) === 'live' && ! $request->user()?->email_verified_at) {
            throw ApiException::conflict(
                'email_unverified',
                'Verify your email address before putting a site on the internet.'
            );
        }

        if (($data['status'] ?? null) === 'live' && ! $site->domains()->whereNotNull('verified_at')->exists()) {
            throw ApiException::conflict(
                'no_verified_domain',
                'Add and verify a domain before putting the site live — otherwise there is no address to visit.'
            );
        }

        if (array_key_exists('locales', $data)) {
            /*
             * Only languages this platform actually speaks, and the site's own always among them.
             *
             * A site published in a language whose chrome does not exist would be a page with
             * translated words and English buttons; and one that dropped its own language from the
             * list would hide the version every untranslated word is written in.
             */
            $own = \App\Support\Locale\Locales::normalise($data['locale'] ?? $site->locale)
                ?? \App\Support\Locale\Locales::FALLBACK;

            $data['locales'] = array_values(array_unique(array_merge([$own], array_filter(array_map(
                fn ($code) => \App\Support\Locale\Locales::normalise($code),
                $data['locales'],
            )))));
        }

        $site->fill($data);

        // Before the write, so the log records what actually moved rather than what was sent.
        $this->audit->recordChange('site.updated', $site);

        $site->save();

        $this->forgetDomains($site);

        return response()->json($this->present($site->fresh(['domains', 'pages', 'menus.items']), withPages: true));
    }

    /* ------------------------------------------------------------------------------ pages */

    public function storePage(Request $request, Site $site)
    {
        $this->authorize($request, 'sites.manage');

        if ($site->pages()->count() >= (int) config('seatmap.sites.limits.max_pages', 200)) {
            throw ApiException::unprocessable('too_many_pages', 'This site has as many pages as it can hold.');
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'slug' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
        ]);

        if ($site->pages()->where('slug', $data['slug'])->exists()) {
            throw ApiException::conflict('slug_taken', 'A page already uses that address.');
        }

        $page = SitePage::create([
            'site_id' => $site->id,
            'slug' => $data['slug'],
            'title' => $data['title'],
            'kind' => 'page',
            'draft_blocks' => [],
            'position' => (int) $site->pages()->max('position') + 1,
        ]);

        return response()->json($this->presentPage($page), 201);
    }

    public function updatePage(Request $request, Site $site, SitePage $page)
    {
        $this->authorize($request, 'sites.manage');
        $this->assertBelongs($site, $page->site_id);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:160'],
            'slug' => ['sometimes', 'string', 'max:80', 'regex:/^[a-z0-9]*(?:-[a-z0-9]+)*$/'],
            'blocks' => ['sometimes', 'array'],
            'seo_title' => ['nullable', 'string', 'max:160'],
            'seo_description' => ['nullable', 'string', 'max:320'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ]);

        if (isset($data['slug']) && $data['slug'] !== $page->slug) {
            if ('home' === $page->kind) {
                throw ApiException::unprocessable('home_slug_fixed', 'The home page lives at the root and cannot move.');
            }

            if ($site->pages()->where('slug', $data['slug'])->where('id', '!=', $page->id)->exists()) {
                throw ApiException::conflict('slug_taken', 'A page already uses that address.');
            }
        }

        if (array_key_exists('blocks', $data)) {
            $data['draft_blocks'] = Blocks::sanitiseAll($data['blocks'], $this->mayUseRawHtml());
            unset($data['blocks']);
        }

        $page->update($data);

        return response()->json($this->presentPage($page->fresh()));
    }

    /**
     * A page's words in another language.
     *
     * An overlay, never a second copy of the page: a title, the two SEO lines, and the text of
     * individual blocks keyed by block id. A copied block tree drifts the moment somebody adds a
     * section to one language and not the other, and nothing in an editor can tell them so.
     *
     * Sent whole per locale, like the prices and the channel quotas: a patch per field across a
     * set of translations is where "the Persian page still says last season" comes from.
     */
    public function translatePage(Request $request, Site $site, SitePage $page)
    {
        $this->authorize($request, 'sites.manage');
        $this->assertBelongs($site, $page->site_id);

        $data = $request->validate([
            'locale' => ['required', 'string', 'max:12'],
            'title' => ['sometimes', 'nullable', 'string', 'max:160'],
            'seo_title' => ['sometimes', 'nullable', 'string', 'max:160'],
            'seo_description' => ['sometimes', 'nullable', 'string', 'max:320'],
            'blocks' => ['sometimes', 'array', 'max:200'],
            'blocks.*' => ['array', 'max:40'],
            'blocks.*.*' => ['nullable', 'string', 'max:20000'],
        ]);

        $locale = \App\Support\Locale\Locales::normalise($data['locale']);

        if (! $locale) {
            throw ApiException::unprocessable('unknown_locale', 'This platform does not speak that language.');
        }

        $translations = $page->translations ?? [];

        $written = array_filter([
            'title' => trim((string) ($data['title'] ?? '')) ?: null,
            'seo_title' => trim((string) ($data['seo_title'] ?? '')) ?: null,
            'seo_description' => trim((string) ($data['seo_description'] ?? '')) ?: null,
            // Empty strings are dropped rather than stored: a blank translation is not a
            // translation, and storing it would blank the original on the way out.
            'blocks' => $this->translatedBlocks($page, $data['blocks'] ?? []),
        ], fn ($value) => null !== $value && [] !== $value);

        if ([] === $written) {
            unset($translations[$locale]);
        } else {
            $translations[$locale] = $written;
        }

        $page->forceFill(['translations' => $translations])->save();

        $this->audit->record('site.page_translated', $page, [
            'locale' => $locale,
            'written' => [] !== $written,
        ]);

        return response()->json($this->presentPage($page->fresh()));
    }

    /**
     * Keep only text that overlays a field the live page actually has.
     *
     * A translation cannot invent a block or a field: that is what makes it an overlay rather than
     * a second editor, and it is why a page cannot end up with two different shapes.
     *
     * @param  array<string, array<string, string|null>>  $sent
     * @return array<string, array<string, string>>
     */
    private function translatedBlocks(SitePage $page, array $sent): array
    {
        $shape = [];

        foreach ($page->draft_blocks ?? [] as $block) {
            $shape[$block['id'] ?? ''] = $block;
        }

        $kept = [];

        foreach ($sent as $id => $fields) {
            $block = $shape[$id] ?? null;

            if (! is_array($block) || ! is_array($fields)) {
                continue;
            }

            $prose = \App\Domain\Sites\Blocks::WORDS[$block['type'] ?? ''] ?? [];

            foreach ($fields as $field => $value) {
                // Prose only, and only prose this kind of block has. A translation is not a way to
                // set a colour, a height or an event id in one language and not another.
                if (! in_array($field, $prose, true) || ! array_key_exists($field, $block)) {
                    continue;
                }

                if (is_string($value) && '' !== trim($value)) {
                    $kept[$id][$field] = $value;
                }
            }
        }

        return $kept;
    }

    /** Publishing copies the draft over the live copy — the same discipline the seat maps have. */
    public function publishPage(Request $request, Site $site, SitePage $page)
    {
        $this->authorize($request, 'sites.publish');
        $this->assertBelongs($site, $page->site_id);

        $page->update([
            'published_blocks' => $page->draft_blocks ?? [],
            'published_at' => now(),
        ]);

        $this->audit->record('site_page.published', $page, ['slug' => $page->slug]);

        return response()->json($this->presentPage($page->fresh()));
    }

    public function destroyPage(Request $request, Site $site, SitePage $page)
    {
        $this->authorize($request, 'sites.manage');
        $this->assertBelongs($site, $page->site_id);

        if (in_array($page->kind, ['home', 'event'], true)) {
            throw ApiException::unprocessable(
                'page_required',
                'The home and event pages are part of how the site works and cannot be removed.'
            );
        }

        $page->delete();

        return response()->json(['deleted' => true]);
    }

    /* ------------------------------------------------------------------------------ menus */

    public function updateMenu(Request $request, Site $site, string $key)
    {
        $this->authorize($request, 'sites.manage');

        $menu = $site->menus()->where('key', $key)->firstOrFail();

        $data = $request->validate([
            'items' => ['present', 'array', 'max:'.config('seatmap.sites.limits.max_menu_items', 60)],
            'items.*.label' => ['required', 'string', 'max:60'],
            // The same label in the site's other languages, keyed by locale. The words in a
            // header are the first thing a visitor reads, and were the last thing on a hosted
            // site that could not be said in their own language.
            'items.*.translations' => ['sometimes', 'array', 'max:12'],
            'items.*.translations.*' => ['nullable', 'string', 'max:60'],
            'items.*.target_type' => ['required', 'in:page,event,url'],
            'items.*.site_page_id' => ['nullable', 'uuid'],
            'items.*.event_id' => ['nullable', 'uuid'],
            'items.*.url' => ['nullable', 'string', 'max:400'],
            'items.*.new_tab' => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($menu, $site, $data) {
            // Replaced wholesale rather than diffed: a menu is short, and sending the intended
            // order each time is the only version where two editors cannot interleave into a mess.
            $menu->items()->delete();

            foreach (array_values($data['items']) as $position => $item) {
                $target = $this->menuTarget($site, $item);

                if (! $target) {
                    continue;
                }

                SiteMenuItem::create([
                    'site_menu_id' => $menu->id,
                    'label' => $item['label'],
                    'translations' => $this->menuWords($item['translations'] ?? []),
                    'target_type' => $item['target_type'],
                    'new_tab' => (bool) ($item['new_tab'] ?? false),
                    'position' => $position,
                ] + $target);
            }
        });

        return response()->json($this->presentMenu($menu->fresh('items')));
    }

    /**
     * Menu labels in other languages: only ones this platform speaks, and only ones with words in.
     *
     * @param  array<string, string|null>  $sent
     * @return array<string, string>
     */
    private function menuWords(array $sent): array
    {
        $kept = [];

        foreach ($sent as $locale => $label) {
            $code = \App\Support\Locale\Locales::normalise((string) $locale);

            if ($code && is_string($label) && '' !== trim($label)) {
                $kept[$code] = trim($label);
            }
        }

        return $kept;
    }

    /* ---------------------------------------------------------------------------- domains */

    public function storeDomain(Request $request, Site $site)
    {
        $this->authorize($request, 'domains.manage');

        if ($site->domains()->count() >= (int) config('seatmap.sites.limits.max_domains', 5)) {
            throw ApiException::unprocessable('too_many_domains', 'This site already has as many addresses as it can hold.');
        }

        $data = $request->validate([
            'hostname' => ['required', 'string', 'max:253'],
        ]);

        $hostname = SiteDomain::normalise($data['hostname']);

        if (! preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/', $hostname)) {
            throw ApiException::unprocessable('invalid_hostname', 'That is not a hostname we can serve.');
        }

        // Checked across every tenant, unscoped: a hostname routes globally, so "taken" has to mean
        // taken by anybody. The message never says who has it.
        $taken = SiteDomain::withoutGlobalScope('tenant')->where('hostname', $hostname)->exists();

        if ($taken) {
            throw ApiException::conflict('hostname_taken', 'That address is already in use.');
        }

        $domain = SiteDomain::create([
            'site_id' => $site->id,
            'hostname' => $hostname,
            'is_primary' => ! $site->domains()->exists(),
            'verification_token' => SiteDomain::newToken(),
        ]);

        VerifySiteDomain::dispatch($domain->id)->afterCommit();

        $this->audit->record('site_domain.added', $domain, ['hostname' => $hostname]);

        return response()->json($this->presentDomain($domain), 201);
    }

    public function verifyDomain(Request $request, Site $site, SiteDomain $domain)
    {
        $this->authorize($request, 'domains.manage');
        $this->assertBelongs($site, $domain->site_id);

        $wasVerified = $domain->isVerified();

        VerifySiteDomain::dispatchSync($domain->id);

        $domain = $domain->fresh();

        // Only the transition. Checking an address that was already verified is somebody being
        // careful, not news.
        if (! $wasVerified && $domain->isVerified()) {
            app(\App\Domain\Notifications\Notifier::class)->raise(
                'domain.verified',
                ['hostname' => $domain->hostname, 'site' => $site->name],
                $domain,
            );
        }

        return response()->json($this->presentDomain($domain));
    }

    public function makeDomainPrimary(Request $request, Site $site, SiteDomain $domain)
    {
        $this->authorize($request, 'domains.manage');
        $this->assertBelongs($site, $domain->site_id);

        if (! $domain->isVerified()) {
            throw ApiException::conflict('domain_unverified', 'Verify the address before making it the main one.');
        }

        DB::transaction(function () use ($site, $domain) {
            $site->domains()->update(['is_primary' => false]);
            $domain->update(['is_primary' => true]);
        });

        $this->forgetDomains($site);

        return response()->json($this->presentDomain($domain->fresh()));
    }

    public function destroyDomain(Request $request, Site $site, SiteDomain $domain)
    {
        $this->authorize($request, 'domains.manage');
        $this->assertBelongs($site, $domain->site_id);

        if ($domain->is_primary && $site->domains()->count() > 1) {
            throw ApiException::conflict('primary_domain', 'Make another address the main one first.');
        }

        $hostname = $domain->hostname;
        $domain->delete();

        SiteResolver::forget($hostname);

        return response()->json(['deleted' => true]);
    }

    /* ---------------------------------------------------------------------------- helpers */

    public function themes(Request $request)
    {
        $this->authorize($request, 'sites.view');

        return response()->json([
            'themes' => array_values(Themes::all()),
            // The account's own themes travel with ours, because a site chooses between them on
            // one screen and should not have to know which kind it is picking.
            'custom' => SiteTheme::orderBy('name')->get()->map(fn (SiteTheme $theme) => [
                'id' => $theme->id,
                'key' => $theme->key,
                'name' => $theme->name,
                'base_key' => $theme->base_key,
                'tokens' => \App\Domain\Sites\ThemeTokens::resolve(
                    Themes::tokens($theme->base_key),
                    (array) $theme->tokens
                ),
            ])->values(),
            'fonts' => array_keys(Themes::FONTS),
            'radii' => array_keys(Themes::RADII),
            'blocks' => Blocks::describe(),
        ]);
    }

    private function menuTarget(Site $site, array $item): ?array
    {
        return match ($item['target_type']) {
            'page' => $site->pages()->whereKey($item['site_page_id'] ?? null)->exists()
                ? ['site_page_id' => $item['site_page_id']]
                : null,
            // Scoped, so a menu cannot be pointed at another organiser's event by guessing an id.
            'event' => \App\Models\Event::whereKey($item['event_id'] ?? null)->exists()
                ? ['event_id' => $item['event_id']]
                : null,
            'url' => ($url = Blocks::href($item['url'] ?? null)) ? ['url' => $url] : null,
            default => null,
        };
    }

    private function brand(array $brand, Site $site): array
    {
        $gateways = array_values(array_filter(
            (array) ($brand['gateways'] ?? ['offline']),
            fn ($key) => is_string($key) && $this->gateways->has($key)
        ));

        return array_filter([
            'accent' => Themes::colour($brand['accent'] ?? null),
            'heading_font' => Themes::font($brand['heading_font'] ?? null),
            'body_font' => Themes::font($brand['body_font'] ?? null),
            'radius' => isset(Themes::RADII[$brand['radius'] ?? '']) ? $brand['radius'] : null,
            'logo_url' => Themes::url($brand['logo_url'] ?? null),
            'tagline' => isset($brand['tagline']) ? mb_substr((string) $brand['tagline'], 0, 160) : null,
            'gateways' => $gateways ?: ['offline'],
        ], fn ($value) => null !== $value);
    }

    /**
     * Raw HTML is gated.
     *
     * Organiser-supplied markup on a domain we serve is a stored-XSS surface that crosses tenants,
     * so it is off unless the organiser's own settings turn it on — which is a deliberate act by
     * someone who has read what it means, not a checkbox in a page editor.
     */
    private function mayUseRawHtml(): bool
    {
        $tenant = app(\App\Support\Tenancy\TenantContext::class)->get();

        return (bool) ($tenant?->settings['allow_raw_html'] ?? false);
    }

    private function assertBelongs(Site $site, string $siteId): void
    {
        if ($site->id !== $siteId) {
            throw ApiException::notFound('Not part of this site.', 'not_part_of_site');
        }
    }

    private function forgetDomains(Site $site): void
    {
        foreach ($site->domains as $domain) {
            SiteResolver::forget($domain->hostname);
        }
    }

    private function present(Site $site, bool $withPages = false): array
    {
        $data = [
            'id' => $site->id,
            'name' => $site->name,
            'theme_key' => $site->theme_key,
            'site_theme_id' => $site->site_theme_id,
            'locale' => $site->locale,
            // The languages this site is published in, its own always among them. Only these are
            // offered in the switcher: a menu of six that means one is a menu that misleads.
            'locales' => $site->publishedLocales(),
            'timezone' => $site->timezone,
            'currency' => $site->currency,
            'invoices_enabled' => (bool) $site->invoices_enabled,
            'legal_name' => $site->legal_name,
            'tax_number' => $site->tax_number,
            'billing_address' => $site->billing_address,
            'invoice_footer' => $site->invoice_footer,
            'invoice_prefix' => $site->invoice_prefix,
            'brand' => $site->brand ?? [],
            'status' => $site->status,
            'google_signin' => (bool) $site->google_signin,
            // So the screen can say why the switch is unavailable rather than showing a dead one.
            'signin_available' => app(GoogleIdentity::class)->configured(),
            'url' => $site->canonicalHost() ? $site->url('/') : null,
            'domains' => $site->domains->map(fn ($d) => $this->presentDomain($d))->values(),
        ];

        if ($withPages) {
            $data['pages'] = $site->pages->map(fn ($p) => $this->presentPage($p))->values();
            $data['menus'] = $site->menus->map(fn ($m) => $this->presentMenu($m))->values();
        }

        return $data;
    }

    private function presentPage(SitePage $page): array
    {
        return [
            'id' => $page->id,
            'slug' => $page->slug,
            'title' => $page->title,
            'kind' => $page->kind,
            'path' => $page->path(),
            'blocks' => $page->draft_blocks ?? [],
            'seo_title' => $page->seo_title,
            'seo_description' => $page->seo_description,
            'position' => $page->position,
            // What has been written in another language, and which ones. The overlay itself comes
            // back so the editor can show it beside the original rather than guessing.
            'translations' => $page->translations ?? [],
            'written_in' => $page->writtenIn(),
            'published_at' => $page->published_at?->toIso8601String(),
            'has_unpublished_changes' => $page->hasUnpublishedChanges(),
        ];
    }

    private function presentMenu(SiteMenu $menu): array
    {
        return [
            'key' => $menu->key,
            'name' => $menu->name,
            'items' => $menu->items->map(fn (SiteMenuItem $item) => [
                'id' => $item->id,
                'label' => $item->label,
                'translations' => $item->translations ?? [],
                'target_type' => $item->target_type,
                'site_page_id' => $item->site_page_id,
                'event_id' => $item->event_id,
                'url' => $item->url,
                'new_tab' => $item->new_tab,
            ])->values(),
        ];
    }

    private function presentDomain(SiteDomain $domain): array
    {
        return [
            'id' => $domain->id,
            'hostname' => $domain->hostname,
            'is_primary' => $domain->is_primary,
            'verified' => $domain->isVerified(),
            'verified_at' => $domain->verified_at?->toIso8601String(),
            'last_checked_at' => $domain->last_checked_at?->toIso8601String(),
            'last_error' => $domain->last_error,
            'record' => $domain->expectedRecord(),
        ];
    }
}
