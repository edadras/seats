<?php

namespace App\Support\Locale;

/**
 * The languages the platform speaks, and the facts about each one that the rest of the code needs
 * (ADR-0005 §1).
 *
 * This list is closed. A locale that is not here has no catalogue, and a request for it falls back
 * rather than rendering keys at somebody's customers.
 */
final class Locales
{
    public const FALLBACK = 'en';

    /**
     * @var array<string, array{name: string, native: string, dir: string, digits: string, region: string, calendar: string}>
     *
     * `digits` says which digit shapes this language's readers expect. Persian and Arabic get their
     * own; a Persian ticket printed with Latin digits reads as a machine's output rather than as a
     * document (ADR-0005 §5).
     *
     * `region` is only used to build an ICU locale when one is needed for formatting. It is not a
     * claim about where the reader is.
     *
     * `calendar` is Persian for Persian: an Iranian reader handed "۲۹ سپتامبر ۲۰۲۶" has to convert
     * it before it names a day they could turn up on. Arabic-speaking countries keep the Gregorian
     * calendar for civil dates, so Arabic does not get the same treatment — this is a fact about
     * each language's readers, not a symmetry to be tidied.
     */
    public const ALL = [
        'fa' => ['name' => 'Persian', 'native' => 'فارسی', 'dir' => 'rtl', 'digits' => 'arabext', 'region' => 'IR', 'calendar' => 'persian'],
        'en' => ['name' => 'English', 'native' => 'English', 'dir' => 'ltr', 'digits' => 'latn', 'region' => 'GB', 'calendar' => 'gregory'],
        'ar' => ['name' => 'Arabic', 'native' => 'العربية', 'dir' => 'rtl', 'digits' => 'arab', 'region' => 'AE', 'calendar' => 'gregory'],
        'de' => ['name' => 'German', 'native' => 'Deutsch', 'dir' => 'ltr', 'digits' => 'latn', 'region' => 'DE', 'calendar' => 'gregory'],
        'fr' => ['name' => 'French', 'native' => 'Français', 'dir' => 'ltr', 'digits' => 'latn', 'region' => 'FR', 'calendar' => 'gregory'],
        'it' => ['name' => 'Italian', 'native' => 'Italiano', 'dir' => 'ltr', 'digits' => 'latn', 'region' => 'IT', 'calendar' => 'gregory'],
    ];

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::ALL);
    }

    public static function supports(?string $locale): bool
    {
        return null !== $locale && isset(self::ALL[$locale]);
    }

    /**
     * The supported locale a tag asks for, or null.
     *
     * `fa-IR`, `FA` and `fa` all mean Persian here. Region is dropped rather than matched: we ship
     * one Persian, and pretending otherwise would mean six catalogues becoming sixty.
     */
    public static function normalise(?string $tag): ?string
    {
        if (! $tag) {
            return null;
        }

        $base = strtolower(explode('-', str_replace('_', '-', trim($tag)))[0]);

        return self::supports($base) ? $base : null;
    }

    public static function direction(string $locale): string
    {
        return self::ALL[$locale]['dir'] ?? 'ltr';
    }

    public static function isRtl(string $locale): bool
    {
        return 'rtl' === self::direction($locale);
    }

    /**
     * The ICU locale to format numbers, dates and money with.
     *
     * The numbering system and the calendar are pinned here rather than left to ICU's default for
     * the region, so a Persian reader gets Persian digits and a Persian calendar whatever server
     * they land on. The browser is handed this same string and honours both.
     */
    public static function icu(string $locale): string
    {
        $entry = self::ALL[$locale] ?? self::ALL[self::FALLBACK];

        return $locale.'-'.$entry['region'].'-u-nu-'.$entry['digits'].'-ca-'.$entry['calendar'];
    }

    /** @return array{name: string, native: string, dir: string, digits: string, region: string, calendar: string} */
    public static function info(string $locale): array
    {
        return self::ALL[$locale] ?? self::ALL[self::FALLBACK];
    }

    /**
     * Parse an `Accept-Language` header into the best supported locale.
     *
     * Quality values are honoured, because a browser that says `de;q=0.9, en;q=0.8` is stating a
     * preference and ignoring it would be rude in exactly the way this whole ADR is about.
     */
    public static function fromAcceptLanguage(?string $header): ?string
    {
        if (! $header) {
            return null;
        }

        $candidates = [];

        foreach (explode(',', $header) as $part) {
            $bits = explode(';', trim($part));
            $tag = trim($bits[0]);
            $quality = 1.0;

            foreach (array_slice($bits, 1) as $parameter) {
                if (preg_match('/^\s*q\s*=\s*([0-9.]+)\s*$/', $parameter, $match)) {
                    $quality = (float) $match[1];
                }
            }

            if ($locale = self::normalise($tag)) {
                // First mention wins for a given locale: `fa-IR;q=1, fa;q=0.5` is one preference.
                $candidates[$locale] ??= $quality;
            }
        }

        if (! $candidates) {
            return null;
        }

        arsort($candidates);

        return array_key_first($candidates);
    }

    /** What the panel's language menu shows: every locale, in its own language. */
    public static function menu(): array
    {
        return array_map(
            fn (string $code) => [
                'code' => $code,
                'name' => self::ALL[$code]['name'],
                'native' => self::ALL[$code]['native'],
                'dir' => self::ALL[$code]['dir'],
            ],
            self::codes()
        );
    }
}
