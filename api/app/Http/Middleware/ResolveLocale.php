<?php

namespace App\Http\Middleware;

use App\Support\Locale\LocaleResolver;
use Closure;
use Illuminate\Http\Request;

/**
 * Puts the request into a language before anything renders a word of it (ADR-0005 §4).
 *
 * Runs after the tenant and site resolvers, because two steps of the decision need them. On the
 * hosted-site front door the site is only known inside the controller, so that controller asks
 * LocaleResolver again — which is why the response headers below are written from the *final*
 * locale rather than from the one chosen here.
 */
class ResolveLocale
{
    public function __construct(private readonly LocaleResolver $resolver) {}

    public function handle(Request $request, Closure $next)
    {
        $this->resolver->apply($request);

        $response = $next($request);

        // Read after the fact, not before: a controller that learned more may have refined it.
        $locale = app()->getLocale();

        if (method_exists($response, 'header')) {
            $response->headers->set('Content-Language', $locale);

            // So a cache in front of us never serves a Persian page to a German reader.
            $vary = $response->headers->get('Vary');
            $response->headers->set('Vary', $vary ? $vary.', Accept-Language' : 'Accept-Language');
        }

        return $response;
    }
}
