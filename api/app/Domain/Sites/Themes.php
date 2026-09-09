<?php

namespace App\Domain\Sites;

use App\Models\Site;
use App\Models\SiteTheme;

/**
 * The looks a site can wear.
 *
 * A theme is a token set and a stylesheet — not code, and not something an organiser uploads. That
 * is the whole reason this platform is not WordPress (ADR-0003): a theme cannot run anything, so
 * choosing one can never be a security decision. It stays true of the themes an organiser writes
 * themselves: those are tokens plus CSS, sanitised on the way in (`ThemeCss`).
 *
 * Three layers, in this order, and each one only overrides what it names:
 *
 *   the first-party theme's tokens — six of them, deliberately different from one another;
 *   a custom theme's tokens and stylesheet, when the site wears one;
 *   the site's own brand, so switching theme keeps an organiser's colours and logo.
 */
class Themes
{
    public const DEFAULT = 'aurora';

    private const THEMES = [
        'aurora' => [
            'name' => 'Aurora',
            'tokens' => [
                'accent' => '#4a4fdc',
                'heading_font' => 'sans',
                'body_font' => 'sans',
                'radius' => 'soft',
                'shadow' => 'soft',
            ],
        ],
        'noir' => [
            'name' => 'Noir',
            'tokens' => [
                'accent' => '#f0455f',
                'on_accent' => '#0d0f14',
                'surface' => '#0d0f14',
                'surface_sunken' => '#14171f',
                'border' => '#262b36',
                'text' => '#f2f4f8',
                'text_muted' => '#98a1b3',
                'heading_font' => 'sans',
                'body_font' => 'sans',
                'radius' => 'sharp',
                'heading_tracking' => 'tight',
                'width' => 'wide',
            ],
        ],
        'playbill' => [
            'name' => 'Playbill',
            'tokens' => [
                'accent' => '#8c2f39',
                'surface' => '#fbf7f0',
                'surface_sunken' => '#f3ece0',
                'border' => '#e0d5c3',
                'text' => '#241f1a',
                'text_muted' => '#6b6055',
                'heading_font' => 'serif',
                'body_font' => 'serif',
                'radius' => 'soft',
                'width' => 'narrow',
            ],
        ],
        'marquee' => [
            'name' => 'Marquee',
            'tokens' => [
                'accent' => '#ffd400',
                'on_accent' => '#101010',
                'surface' => '#101010',
                'surface_sunken' => '#191919',
                'border' => '#2c2c2c',
                'text' => '#fafafa',
                'text_muted' => '#a3a3a3',
                'heading_font' => 'condensed',
                'body_font' => 'sans',
                'heading_case' => 'upper',
                'heading_weight' => 'heavy',
                'heading_tracking' => 'wide',
                'radius' => 'sharp',
                'button_shape' => 'square',
                'width' => 'wide',
            ],
        ],
        'atrium' => [
            'name' => 'Atrium',
            'tokens' => [
                'accent' => '#1f5f4d',
                'surface' => '#ffffff',
                'surface_sunken' => '#f4f4f1',
                'border' => '#e6e5df',
                'text' => '#1c1d1a',
                'text_muted' => '#6a6c66',
                'heading_font' => 'serif',
                'body_font' => 'sans',
                'heading_weight' => 'regular',
                'heading_tracking' => 'normal',
                'radius' => 'sharp',
                'button_shape' => 'square',
                'density' => 'roomy',
                'width' => 'wide',
                'shadow' => 'none',
            ],
        ],
        'kiosk' => [
            'name' => 'Kiosk',
            'tokens' => [
                'accent' => '#0b6bcb',
                'surface' => '#ffffff',
                'surface_sunken' => '#eef1f6',
                'border' => '#cdd5e0',
                'text' => '#101623',
                'text_muted' => '#54607a',
                'heading_font' => 'grotesk',
                'body_font' => 'sans',
                'heading_weight' => 'bold',
                'heading_tracking' => 'tight',
                'radius' => 'sharp',
                'button_shape' => 'follow',
                'density' => 'compact',
                'width' => 'normal',
            ],
        ],
    ];

    /** Kept as an alias so callers do not have to know where the table moved to. */
    public const FONTS = ThemeTokens::FONTS;

    public const RADII = ThemeTokens::RADII;

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
            fn (string $key) => [
                'key' => $key,
                'name' => self::THEMES[$key]['name'],
                'description' => __('themes.descriptions.'.$key),
                'tokens' => ThemeTokens::resolve(self::THEMES[$key]['tokens']),
            ],
            array_combine(self::keys(), self::keys())
        );
    }

    /** A theme's own tokens, before anything a site says. */
    public static function tokens(string $key): array
    {
        return self::THEMES[self::exists($key) ? $key : self::DEFAULT]['tokens'];
    }

    /** Kept for callers that still ask for the four brand fields by their old names. */
    public static function defaults(string $key): array
    {
        $tokens = ThemeTokens::resolve(self::tokens($key));

        return [
            'accent' => $tokens['accent'],
            'heading_font' => $tokens['heading_font'],
            'body_font' => $tokens['body_font'],
            'radius' => $tokens['radius'],
        ];
    }

    /**
     * Everything a page needs to render in this site's clothes.
     *
     * `tokens` is the resolved token set; `css` is the site's own stylesheet, already sanitised;
     * `base_key` is the first-party stylesheet to link. The brand fields stay where they were, so
     * the templates and the ticket email keep working unchanged.
     */
    public static function resolveBrand(string $themeKey, array $brand, ?SiteTheme $custom = null): array
    {
        $base = $custom ? $custom->base_key : $themeKey;

        $tokens = ThemeTokens::resolve(
            self::tokens($base),
            $custom ? (array) $custom->tokens : [],
            // The site's brand is last: an organiser's own colour outranks the theme they chose,
            // which is what makes switching themes safe.
            array_filter([
                'accent' => $brand['accent'] ?? null,
                'heading_font' => $brand['heading_font'] ?? null,
                'body_font' => $brand['body_font'] ?? null,
                'radius' => $brand['radius'] ?? null,
            ], fn ($value) => null !== $value),
        );

        return [
            'base_key' => self::exists($base) ? $base : self::DEFAULT,
            'tokens' => $tokens,
            'token_css' => ThemeTokens::css($tokens),
            'css' => $custom ? ThemeCss::sanitise($custom->css) : '',

            'accent' => $tokens['accent'],
            'heading_font' => $tokens['heading_font'],
            'body_font' => $tokens['body_font'],
            'radius' => $tokens['radius'],
            'heading_family' => ThemeTokens::FONTS[$tokens['heading_font']],
            'body_family' => ThemeTokens::FONTS[$tokens['body_font']],
            'radius_value' => ThemeTokens::RADII[$tokens['radius']],
            'logo_url' => self::url($brand['logo_url'] ?? null),
            'tagline' => isset($brand['tagline']) ? (string) $brand['tagline'] : null,
        ];
    }

    /** The clothes a particular site is wearing, custom theme included. */
    public static function forSite(Site $site): array
    {
        return self::resolveBrand($site->theme_key, $site->brand ?? [], $site->customTheme);
    }

    public static function colour(mixed $value): ?string
    {
        return ThemeTokens::colour($value);
    }

    public static function font(mixed $value): ?string
    {
        return is_string($value) && isset(ThemeTokens::FONTS[$value]) ? $value : null;
    }

    /** Only http(s), so a logo can never be a `javascript:` or `data:` URL in an <img src>. */
    public static function url(mixed $value): ?string
    {
        if (! is_string($value) || '' === trim($value)) {
            return null;
        }

        $scheme = mb_strtolower((string) parse_url($value, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $value : null;
    }
}
