<?php

namespace App\Http\Controllers\Site\Concerns;

use App\Domain\Sites\Themes;
use App\Models\Site;
use App\Support\Locale\Money;

/**
 * The shell every hosted page is rendered into.
 *
 * The layout needs the same eight things on every page — the brand, the menus, the meta tags it
 * may or may not have — and a controller that forgets one of them renders a page with a hole in
 * it. This was written out three times before it was written down once.
 */
trait RendersSitePages
{
    protected function view(Site $site, string $template, array $data)
    {
        $currency = $data['currency'] ?? $site->currency;

        return response()->view($template, $data + [
            'money' => fn (int $minor) => Money::format($minor, $currency ?: 'EUR'),
            'site' => $site,
            'brand' => Themes::forSite($site),
            // The meta a page may set and usually does not. Declared here so the layout can read
            // them without every page having to remember to pass a null.
            'description' => null,
            'canonical' => null,
            'image' => null,
            'jsonld' => null,
            'headerMenu' => $site->menuFor('header'),
            'footerMenu' => $site->menuFor('footer'),
        ]);
    }
}
