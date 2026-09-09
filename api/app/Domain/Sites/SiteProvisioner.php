<?php

namespace App\Domain\Sites;

use App\Models\Event;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteMenu;
use App\Models\SiteMenuItem;
use App\Models\SitePage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a site that is already worth looking at.
 *
 * An empty site is a worse starting point than a wrong one: an organiser who lands on a blank
 * screen has to guess what a page even is. So a new site arrives with a home page that lists what
 * is on, an information page, an event page for the picker, and menus wired to all three.
 */
class SiteProvisioner
{
    public function create(string $name, array $attributes = []): Site
    {
        return DB::transaction(function () use ($name, $attributes) {
            $locale = $attributes['locale'] ?? 'en';

            $site = Site::create([
                'name' => $name,
                'theme_key' => Themes::exists($attributes['theme_key'] ?? '') ? $attributes['theme_key'] : Themes::DEFAULT,
                'locale' => $locale,
                'timezone' => $attributes['timezone'] ?? 'UTC',
                'currency' => strtoupper($attributes['currency'] ?? 'EUR'),
                'brand' => $attributes['brand'] ?? [],
                'status' => 'draft',
            ]);

            /*
             * The starter content is written in the *site's* language, not the language of whoever
             * happened to click Create. It is real content on a real page: an organiser whose site
             * is Persian should not have to translate three pages of English before going live.
             */
            $say = fn (string $key) => __('site.seed.'.$key, [], $locale);

            $home = $this->page($site, '', $say('home'), 'home', 0, [
                ['type' => 'heading', 'text' => $name, 'level' => 2, 'align' => 'center'],
                ['type' => 'richText', 'text' => $say('welcome')],
                ['type' => 'eventList', 'title' => $say('whatsOn'), 'limit' => 12, 'layout' => 'cards'],
            ]);

            $event = $this->page($site, 'event', $say('event'), 'event', 1, [
                ['type' => 'eventDetail', 'event_public_id' => ''],
            ]);

            $visiting = $this->page($site, 'visiting', $say('visiting'), 'page', 2, [
                ['type' => 'heading', 'text' => $say('visiting'), 'level' => 2, 'align' => 'start'],
                ['type' => 'venueMap', 'title' => $say('findingUs'), 'address' => '', 'directions' => ''],
                ['type' => 'faq', 'title' => $say('beforeYouCome'), 'items' => [
                    ['question' => $say('doorsQuestion'), 'answer' => $say('doorsAnswer')],
                    ['question' => $say('refundQuestion'), 'answer' => $say('refundAnswer')],
                ]],
            ]);

            $header = SiteMenu::create([
                'site_id' => $site->id, 'key' => 'header', 'name' => $say('header'),
            ]);

            SiteMenu::create([
                'site_id' => $site->id, 'key' => 'footer', 'name' => $say('footer'),
            ]);

            SiteMenuItem::create([
                'site_menu_id' => $header->id, 'label' => $say('whatsOn'),
                'target_type' => 'page', 'site_page_id' => $home->id, 'position' => 0,
            ]);

            SiteMenuItem::create([
                'site_menu_id' => $header->id, 'label' => $say('visiting'),
                'target_type' => 'page', 'site_page_id' => $visiting->id, 'position' => 1,
            ]);

            $this->attachDefaultDomain($site);

            return $site->fresh(['domains', 'pages', 'menus.items']);
        });
    }

    /**
     * A free subdomain, so a site can be looked at before anyone touches DNS.
     *
     * Marked verified on creation because we own the parent domain: there is nothing to prove.
     */
    private function attachDefaultDomain(Site $site): void
    {
        $parent = trim((string) config('seatmap.sites.default_domain'));

        if ('' === $parent) {
            return;
        }

        $base = Str::slug($site->name) ?: 'site';
        $hostname = $base.'.'.$parent;
        $suffix = 1;

        while (SiteDomain::withoutGlobalScope('tenant')->where('hostname', $hostname)->exists()) {
            $hostname = $base.'-'.(++$suffix).'.'.$parent;
        }

        SiteDomain::create([
            'site_id' => $site->id,
            'hostname' => SiteDomain::normalise($hostname),
            'is_primary' => true,
            'verification_token' => SiteDomain::newToken(),
            'verified_at' => now(),
        ]);
    }

    private function page(Site $site, string $slug, string $title, string $kind, int $position, array $blocks): SitePage
    {
        $clean = Blocks::sanitiseAll($blocks);

        return SitePage::create([
            'site_id' => $site->id,
            'slug' => $slug,
            'title' => $title,
            'kind' => $kind,
            'draft_blocks' => $clean,
            // Published straight away: a new site that is invisible until someone finds a publish
            // button is a new site that gets reported as broken.
            'published_blocks' => $clean,
            'published_at' => now(),
            'position' => $position,
        ]);
    }
}
