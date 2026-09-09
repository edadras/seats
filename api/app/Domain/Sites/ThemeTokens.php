<?php

namespace App\Domain\Sites;

/**
 * The vocabulary a theme is written in.
 *
 * A theme on this platform is tokens and a stylesheet — never code (ADR-0003). That is the whole
 * reason choosing a theme can never be a security decision, and it is why this file is a closed
 * list rather than a passthrough: every token is either a hex colour or a key into a table of
 * values written here. An arbitrary string never reaches a `<style>` block, because a
 * `font-family` an organiser typed is an injection into a stylesheet.
 *
 * Each token names the CSS custom property it sets, and the site stylesheet is written against
 * those properties. So a theme moves what a site looks like without a rule being edited, and a
 * custom stylesheet — which organisers may also write — is for the things tokens cannot say.
 */
class ThemeTokens
{
    /** Typefaces are named, not free text. */
    public const FONTS = [
        'sans' => '"Inter var", Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
        'serif' => 'Georgia, "Iowan Old Style", "Times New Roman", serif',
        'mono' => 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace',
        'grotesk' => '"Space Grotesk", "Inter var", Inter, system-ui, sans-serif',
        'condensed' => '"Oswald", "Roboto Condensed", "Arial Narrow", system-ui, sans-serif',
    ];

    public const RADII = ['sharp' => '2px', 'soft' => '10px', 'round' => '22px'];

    /**
     * The whole token table.
     *
     * `colour` takes a hex value; `choice` takes a key from its own options. Nothing else exists.
     */
    public const TOKENS = [
        'accent' => ['kind' => 'colour', 'var' => '--accent', 'group' => 'colour', 'default' => '#4a4fdc'],
        'on_accent' => ['kind' => 'colour', 'var' => '--on-accent', 'group' => 'colour', 'default' => '#ffffff'],
        'surface' => ['kind' => 'colour', 'var' => '--surface', 'group' => 'colour', 'default' => '#ffffff'],
        'surface_sunken' => ['kind' => 'colour', 'var' => '--surface-sunken', 'group' => 'colour', 'default' => '#f6f7f9'],
        'border' => ['kind' => 'colour', 'var' => '--border', 'group' => 'colour', 'default' => '#e3e6ec'],
        'text' => ['kind' => 'colour', 'var' => '--text', 'group' => 'colour', 'default' => '#171a21'],
        'text_muted' => ['kind' => 'colour', 'var' => '--text-muted', 'group' => 'colour', 'default' => '#5f6878'],

        'heading_font' => ['kind' => 'choice', 'var' => '--font-heading', 'group' => 'type',
            'options' => self::FONTS, 'default' => 'sans'],
        'body_font' => ['kind' => 'choice', 'var' => '--font-body', 'group' => 'type',
            'options' => self::FONTS, 'default' => 'sans'],
        'heading_weight' => ['kind' => 'choice', 'var' => '--heading-weight', 'group' => 'type',
            'options' => ['regular' => '500', 'bold' => '700', 'heavy' => '800'], 'default' => 'bold'],
        'heading_case' => ['kind' => 'choice', 'var' => '--heading-case', 'group' => 'type',
            'options' => ['normal' => 'none', 'upper' => 'uppercase'], 'default' => 'normal'],
        'heading_tracking' => ['kind' => 'choice', 'var' => '--heading-tracking', 'group' => 'type',
            'options' => ['tight' => '-0.03em', 'normal' => '-0.015em', 'wide' => '0.04em'], 'default' => 'normal'],

        'radius' => ['kind' => 'choice', 'var' => '--radius', 'group' => 'shape',
            'options' => self::RADII, 'default' => 'soft'],
        'button_shape' => ['kind' => 'choice', 'var' => '--button-radius', 'group' => 'shape',
            'options' => ['follow' => 'var(--radius)', 'pill' => '999px', 'square' => '0'], 'default' => 'follow'],
        'shadow' => ['kind' => 'choice', 'var' => '--shadow', 'group' => 'shape',
            'options' => [
                'none' => 'none',
                'soft' => '0 1px 2px rgba(16,20,30,0.05), 0 10px 30px rgba(16,20,30,0.06)',
                'strong' => '0 2px 6px rgba(16,20,30,0.10), 0 18px 50px rgba(16,20,30,0.14)',
            ], 'default' => 'none'],

        'width' => ['kind' => 'choice', 'var' => '--shell', 'group' => 'layout',
            'options' => ['narrow' => '58rem', 'normal' => '68rem', 'wide' => '80rem'], 'default' => 'normal'],
        'density' => ['kind' => 'choice', 'var' => '--space', 'group' => 'layout',
            'options' => ['compact' => '0.9rem', 'normal' => '1.25rem', 'roomy' => '1.75rem'], 'default' => 'normal'],
    ];

    public static function keys(): array
    {
        return array_keys(self::TOKENS);
    }

    public static function defaults(): array
    {
        return array_map(fn (array $token) => $token['default'], self::TOKENS);
    }

    /**
     * Keep what is valid, drop what is not.
     *
     * Dropping rather than rejecting is deliberate: a token this version does not know about
     * arrives from a newer panel, or from a theme exported later, and losing one control is a
     * better outcome for a site that is selling than a refused save.
     */
    public static function sanitise(array $given): array
    {
        $clean = [];

        foreach (self::TOKENS as $key => $token) {
            if (! array_key_exists($key, $given)) {
                continue;
            }

            $value = $given[$key];

            if ('colour' === $token['kind']) {
                $colour = self::colour($value);

                if (null !== $colour) {
                    $clean[$key] = $colour;
                }

                continue;
            }

            if (is_string($value) && array_key_exists($value, $token['options'])) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    /** The values behind a token set: defaults, then whatever was set on top. */
    public static function resolve(array ...$layers): array
    {
        $resolved = self::defaults();

        foreach ($layers as $layer) {
            $resolved = array_merge($resolved, self::sanitise($layer));
        }

        return $resolved;
    }

    /** A `:root` block. Values come from the table above, never from the caller. */
    public static function css(array $tokens): string
    {
        $resolved = self::resolve($tokens);
        $lines = [];

        foreach (self::TOKENS as $key => $token) {
            $value = 'colour' === $token['kind']
                ? $resolved[$key]
                : $token['options'][$resolved[$key]];

            $lines[] = $token['var'].':'.$value;
        }

        return ':root{'.implode(';', $lines).'}';
    }

    public static function colour(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value)
            ? mb_strtolower($value)
            : null;
    }

    /**
     * What the panel needs to draw the controls and to render a live preview.
     *
     * The option *values* travel too, so the editor can compile a preview stylesheet without
     * keeping a second copy of this table. Two copies of what `roomy` means is two answers, and
     * the wrong one is always the one somebody is looking at.
     */
    public static function describe(): array
    {
        return array_values(array_map(
            fn (string $key) => [
                'key' => $key,
                'kind' => self::TOKENS[$key]['kind'],
                'group' => self::TOKENS[$key]['group'],
                'var' => self::TOKENS[$key]['var'],
                'default' => self::TOKENS[$key]['default'],
                'options' => 'choice' === self::TOKENS[$key]['kind']
                    ? array_keys(self::TOKENS[$key]['options'])
                    : null,
                'values' => 'choice' === self::TOKENS[$key]['kind']
                    ? self::TOKENS[$key]['options']
                    : null,
            ],
            self::keys()
        ));
    }
}
