<?php

namespace App\Domain\Sites;

/**
 * Stylesheets an organiser writes.
 *
 * Tokens cover what most sites need; a stylesheet covers the rest, and refusing to offer one only
 * means the answer to "can I move that heading" is no. So it is offered — and treated as hostile
 * input, because a stylesheet we serve from the organiser's own domain is served with our origin's
 * trust and can be written by any staff member with the sites permission.
 *
 * What that means concretely:
 *
 *   `<` and `>` never survive. Nothing in CSS needs them, and `</style>` inside a `<style>` block
 *   ends the block — turning a stylesheet into markup, which is stored XSS with extra steps.
 *
 *   `@import` never survives. It fetches a third party's stylesheet from the visitor's browser at
 *   render time, which is both a privacy leak and an unreviewed change to the page.
 *
 *   `url(...)` survives only for http(s) and `data:image/...`. Not `javascript:`, which some
 *   browsers still honour in some properties, and not `data:text/html`.
 *
 *   `expression(`, `behavior:`, `-moz-binding:` never survive. All three are ways old engines were
 *   persuaded to run script from a stylesheet.
 *
 * Everything else is left exactly as written: this is not a CSS parser and does not pretend to be
 * one. A rule this does not understand is a rule the browser ignores, which is CSS working as
 * designed.
 */
class ThemeCss
{
    public const MAX_BYTES = 40000;

    private const BANNED = [
        '/@import\b[^;{]*;?/i',
        '/expression\s*\(/i',
        '/behaviou?r\s*:/i',
        '/-moz-binding\s*:/i',
    ];

    public static function sanitise(?string $css): string
    {
        if (null === $css || '' === trim($css)) {
            return '';
        }

        $clean = mb_substr($css, 0, self::MAX_BYTES);

        // Angle brackets first: everything after this cannot close the style element it lands in.
        $clean = str_replace(['<', '>'], '', $clean);

        foreach (self::BANNED as $pattern) {
            $clean = preg_replace($pattern, '', $clean) ?? '';
        }

        // Any url() whose target is not plainly a picture or a page becomes `url(about:blank)`,
        // which is inert and — unlike deleting the declaration — leaves the rule readable, so an
        // organiser can see what happened to it.
        $clean = preg_replace_callback(
            '/url\(\s*([\'"]?)([^\'")]*)\1\s*\)/i',
            fn (array $match) => self::allowedUrl($match[2]) ? $match[0] : 'url(about:blank)',
            $clean
        ) ?? '';

        return trim($clean);
    }

    /** Did anything have to be taken out? Used to tell the author rather than silently differing. */
    public static function changed(?string $css): bool
    {
        return trim((string) $css) !== self::sanitise($css);
    }

    private static function allowedUrl(string $url): bool
    {
        $url = trim($url);

        if ('' === $url) {
            return false;
        }

        if (str_starts_with($url, '//') || str_starts_with($url, '/') || str_starts_with($url, '.')) {
            return true; // Same site, or relative to it.
        }

        if (preg_match('/^data:image\/(png|jpeg|gif|webp|svg\+xml);/i', $url)) {
            return true;
        }

        return (bool) preg_match('/^https?:\/\//i', $url);
    }
}
