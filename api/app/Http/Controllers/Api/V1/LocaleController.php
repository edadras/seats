<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Locale\Locales;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Hands the browser its half of the catalogue (ADR-0005 §1).
 *
 * The catalogues are PHP files under `api/lang/` and they are the single source of truth. This
 * endpoint projects the browser-facing namespaces out of them as JSON rather than keeping a second
 * copy in a `.js` file, because two copies of a translation is two translations, and the one that
 * is wrong is always the one nobody is looking at.
 *
 * Public and cacheable: none of this is secret, all of it is needed before sign-in (the login
 * screen has words on it too), and it changes only when the platform is deployed.
 */
class LocaleController extends Controller
{
    /**
     * Namespaces the browser needs. `errors` is here because the panel shows API failures, and
     * `mail` is not, because nothing in a browser renders an email.
     */
    private const BROWSER_NAMESPACES = ['panel', 'site', 'errors', 'modules', 'payments', 'team', 'pricing', 'themes', 'reports', 'messaging', 'signup'];

    /** The language menu, and which one this request resolved to. */
    public function index(Request $request)
    {
        return response()->json([
            'locales' => Locales::menu(),
            'current' => $request->attributes->get('locale', app()->getLocale()),
            'fallback' => Locales::FALLBACK,
        ]);
    }

    public function show(string $locale)
    {
        if (! Locales::supports($locale)) {
            throw new NotFoundHttpException('No catalogue for that language.');
        }

        $messages = [];

        foreach (self::BROWSER_NAMESPACES as $namespace) {
            $file = lang_path($locale.'/'.$namespace.'.php');

            // A namespace that does not exist for this locale is not an error here — the i18n check
            // is what fails a build over that, loudly, rather than a buyer's browser failing quietly.
            if (is_file($file)) {
                $messages[$namespace] = require $file;
            }
        }

        return response()
            ->json([
                'locale' => $locale,
                'dir' => Locales::direction($locale),
                /*
                 * The language's own default calendar, never the reader's account's.
                 *
                 * This response is cached for a day and marked `public`, so anything account-shaped
                 * in it would reach the next account through a shared cache. The venue's own
                 * calendar arrives separately, on `/auth/me`, and the panel composes the two.
                 */
                'icu' => \App\Support\Locale\Calendars::runAs('auto', fn () => Locales::icu($locale)),
                'messages' => $messages,
            ])
            // A day, and revalidated: catalogues change on deploy, and a stale one shows a person
            // yesterday's wording, which is survivable. An hour of it is not worth the requests.
            ->header('Cache-Control', 'public, max-age=86400, must-revalidate');
    }
}
