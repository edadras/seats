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
            $site = Site::create([
                'name' => $name,
                'theme_key' => Themes::exists($attributes['theme_key'] ?? '') ? $attributes['theme_key'] : Themes::DEFAULT,
                'locale' => $attributes['locale'] ?? 'en',
                'timezone' => $attributes['timezone'] ?? 'UTC',
                'currency' => strtoupper($attributes['currency'] ?? 'EUR'),
                'brand' => $attributes['brand'] ?? [],
                'status' => 'draft',
            ]);

            $home = $this->page($site, '', 'Home', 'home', 0, [
                ['type' => 'heading', 'text' => $name, 'level' => 2, 'align' => 'center'],
                ['type' => 'richText', 'text' => "Welcome. Tickets for everything we've got coming up are below."],
                ['type' => 'eventList', 'title' => "What's on", 'limit' => 12, 'layout' => 'cards'],
            ]);

            $event = $this->page($site, 'event', 'Event', 'event', 1, [
                ['type' => 'eventDetail', 'event_public_id' => ''],
            ]);

            $visiting = $this->page($site, 'visiting', 'Visiting', 'page', 2, [
                ['type' => 'heading', 'text' => 'Visiting', 'level' => 2, 'align' => 'start'],
                ['type' => 'venueMap', 'title' => 'Finding us', 'address' => '', 'directions' => ''],
                ['type' => 'faq', 'title' => 'Before you come', 'items' => [
                    ['question' => 'When do doors open?', 'answer' => 'Usually half an hour before the start time.'],
                    ['question' => 'Can I get a refund?', 'answer' => 'Tell your customers your policy here.'],
                ]],
            ]);

            $header = SiteMenu::create(['site_id' => $site->id, 'key' => 'header', 'name' => 'Header']);
            SiteMenu::create(['site_id' => $site->id, 'key' => 'footer', 'name' => 'Footer']);

            SiteMenuItem::create([
                'site_menu_id' => $header->id, 'label' => "What's on",
                'target_type' => 'page', 'site_page_id' => $home->id, 'position' => 0,
            ]);

            SiteMenuItem::create([
                'site_menu_id' => $header->id, 'label' => 'Visiting',
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
