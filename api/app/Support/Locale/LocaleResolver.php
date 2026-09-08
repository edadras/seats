<?php

namespace App\Support\Locale;

use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * Decides which language a request is answered in (ADR-0005 §4).
 *
 * The order runs from the nearest person outwards: what they just asked for, then what they told us
 * once, then what the thing they are looking at is written in, then what their browser prefers. It
 * ends at English rather than at nothing.
 *
 * A service rather than only a middleware because the hosted-site path binds its site *inside a
 * controller* — routing cannot make that decision, as FrontDoorController explains — so by the time
 * the site is known the middleware has already run. That controller asks again, and this is the one
 * implementation both callers share.
 */
class LocaleResolver
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function resolve(Request $request): string
    {
        // 1. An explicit choice on this request. `?lang=` exists for the one case that matters: a
        //    link somebody was sent, in a language they can read.
        $explicit = Locales::normalise($request->query('lang'))
            ?? Locales::normalise($request->header('X-Seatmap-Locale'));

        if ($explicit) {
            return $explicit;
        }

        // 2. A choice they made before, remembered for the session.
        if ($request->hasSession() && $chosen = Locales::normalise($request->session()->get('locale'))) {
            return $chosen;
        }

        // 3. The signed-in person's own preference, which outranks anything about the content: a
        //    German-speaking member of a Persian organiser's staff works in German.
        if ($user = $request->user()) {
            if ($preferred = Locales::normalise($user->locale ?? null)) {
                return $preferred;
            }
        }

        // 4. What the visitor is actually looking at. A buyer on an organiser's Persian site gets
        //    Persian without being asked.
        $site = $request->attributes->get('site');

        if ($site && $siteLocale = Locales::normalise($site->locale ?? null)) {
            return $siteLocale;
        }

        if ($tenant = $this->tenantContext->get()) {
            if ($tenantLocale = Locales::normalise($tenant->locale ?? null)) {
                return $tenantLocale;
            }
        }

        return Locales::fromAcceptLanguage($request->header('Accept-Language')) ?? Locales::FALLBACK;
    }

    /** Resolve and apply, returning what was chosen. */
    public function apply(Request $request): string
    {
        $locale = $this->resolve($request);

        app()->setLocale($locale);
        $request->attributes->set('locale', $locale);

        // A visitor who picks a language in the footer means it for the rest of their visit, not
        // for one page. Without this, the next link they click quietly puts them back where the
        // guess had them — which reads as the choice not having worked.
        if ($request->hasSession() && Locales::normalise($request->query('lang'))) {
            $request->session()->put('locale', $locale);
        }

        return $locale;
    }
}
