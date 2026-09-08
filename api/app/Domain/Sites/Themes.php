<?php

namespace App\Domain\Sites;

/**
 * The first-party themes a site can wear.
 *
 * A theme is a stylesheet and a set of token defaults — not code, and not something an organiser
 * can upload. That is the whole reason this platform is not WordPress (ADR-0003): a theme cannot
 * run anything, so choosing one can never be a security decision.
 *
 * Brand values from the site override a theme's defaults, so switching theme keeps the organiser's
 * colours and logo rather than resetting them.
 */
class Themes
{
    public const DEFAULT = 'aurora';

    private const THEMES = [
        'aurora' => [
            'name' => 'Aurora',
            'description' => 'Light, roomy and quiet. Photographs and long descriptions look their best.',
            'defaults' => [
                'accent' => '#4a4fdc',
                'heading_font' => 'sans',
                'body_font' => 'sans',
                'radius' => 'soft',
            ],
        ],
        'noir' => [
            'name' => 'Noir',
            'description' => 'Dark and high contrast. Made for gigs, clubs and late shows.',
            'defaults' => [
                'accent' => '#f0455f',
                'heading_font' => 'sans',
                'body_font' => 'sans',
                'radius' => 'sharp',
            ],
        ],
        'playbill' => [
            'name' => 'Playbill',
            'description' => 'Warm paper and serif headings, in the manner of a printed programme.',
            'defaults' => [
                'accent' => '#8c2f39',
                'heading_font' => 'serif',
                'body_font' => 'serif',
                'radius' => 'soft',
            ],
        ],
    ];

    /** Typefaces are named, not free text: an arbitrary font-family is an injection into a style. */
    public const FONTS = [
        'sans' => '"Inter var", Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
        'serif' => 'Georgia, "Iowan Old Style", "Times New Roman", serif',
        'mono' => 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace',
    ];

    public const RADII = ['sharp' => '2px', 'soft' => '10px', 'round' => '22px'];

    public static function keys(): array
    {
        return array_keys(self::THEMES);
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::THEMES);
    }

    public static function all(): array
    {
        return array_map(
            fn (string $key) => ['key' => $key] + self::THEMES[$key],
            array_combine(self::keys(), self::keys())
        );
    }

    public static function defaults(string $key): array
    {
        return self::THEMES[$key]['defaults'] ?? self::THEMES[self::DEFAULT]['defaults'];
    }

    /**
     * The brand a page is rendered with: the theme's defaults, overridden by whatever the organiser
     * set, with every value validated. An unknown font or a malformed colour falls back rather than
     * reaching the stylesheet, because these end up inside a `<style>` block.
     */
    public static function resolveBrand(string $themeKey, array $brand): array
    {
        $defaults = self::defaults($themeKey);

        $accent = self::colour($brand['accent'] ?? null) ?? $defaults['accent'];
        $headingFont = self::font($brand['heading_font'] ?? null) ?? $defaults['heading_font'];
        $bodyFont = self::font($brand['body_font'] ?? null) ?? $defaults['body_font'];
        $radius = isset(self::RADII[$brand['radius'] ?? '']) ? $brand['radius'] : $defaults['radius'];

        return [
            'accent' => $accent,
            'heading_font' => $headingFont,
            'body_font' => $bodyFont,
            'radius' => $radius,
            'heading_family' => self::FONTS[$headingFont],
            'body_family' => self::FONTS[$bodyFont],
            'radius_value' => self::RADII[$radius],
            'logo_url' => self::url($brand['logo_url'] ?? null),
            'tagline' => isset($brand['tagline']) ? (string) $brand['tagline'] : null,
        ];
    }

    public static function colour(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : null;
    }

    public static function font(mixed $value): ?string
    {
        return is_string($value) && isset(self::FONTS[$value]) ? $value : null;
    }

    /** Only http(s), so a logo can never be a `javascript:` or `data:` URL in an <img src>. */
    public static function url(mixed $value): ?string
    {
        if (! is_string($value) || '' === trim($value)) {
            return null;
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $value : null;
    }
}
