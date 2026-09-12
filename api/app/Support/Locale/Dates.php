<?php

namespace App\Support\Locale;

use DateTimeInterface;
use IntlDateFormatter;

/**
 * Dates and times, written the way the reader writes them (ADR-0005 §5).
 *
 * Not Carbon's `format()`, which prints English month names in every language, and not its
 * `isoFormat()` either: that translates the words but leaves the digits Latin, so a Persian date
 * comes out as "سه‌شنبه 29 سپتامبر · ۲۱:۳۰" — half in one script and half in another, which reads
 * as a bug even to someone who cannot say why.
 *
 * ICU does the whole job at once: month names, digit shapes, and the calendar itself. A Persian
 * reader gets ۸ مهر ۱۴۰۵, not ۲۹ سپتامبر ۲۰۲۶, because the second is a date they would have to
 * convert before it means anything.
 */
final class Dates
{
    /**
     * Format with an explicit ICU pattern.
     *
     * @param string $pattern An ICU date pattern — `d`, `MMM`, `EEE d MMM`, `HH:mm`.
     */
    public static function pattern(?DateTimeInterface $when, string $pattern, ?string $locale = null): string
    {
        if (! $when) {
            return '';
        }

        $locale = self::locale($locale);

        // The formatter is built by hand rather than through IntlDateFormatter::formatObject().
        // That shortcut ignores the calendar: it applies the pattern with a Gregorian calendar
        // whatever the locale asks for, so Persian came out as "۲۹ سپتامبر ۲۰۲۶" — Persian words,
        // Persian digits, and a year no Iranian calendar has.
        $formatter = new IntlDateFormatter(
            Locales::icu($locale),
            IntlDateFormatter::FULL,
            IntlDateFormatter::SHORT,
            $when->getTimezone(),
            // TRADITIONAL means "whatever calendar the locale string asks for", which is where the
            // venue's own choice arrives — see Calendars.
            'gregory' === Calendars::current($locale)
                ? IntlDateFormatter::GREGORIAN
                : IntlDateFormatter::TRADITIONAL,
        );

        $formatter->setPattern($pattern);

        $formatted = $formatter->format($when);

        // ICU returns false on a pattern it cannot use. Better a plain ISO date on a ticket than
        // the word "false".
        return false === $formatted ? $when->format('Y-m-d H:i') : $formatted;
    }

    /** Day of the month on its own — the big number on an event card. */
    public static function day(?DateTimeInterface $when, ?string $locale = null): string
    {
        return self::pattern($when, 'd', $locale);
    }

    /** Short month, for the line under that number. */
    public static function month(?DateTimeInterface $when, ?string $locale = null): string
    {
        return self::pattern($when, 'MMM', $locale);
    }

    /** "Tue 29 Sep · 21:30" — enough to decide whether you are free. */
    public static function shortWhen(?DateTimeInterface $when, ?string $locale = null): string
    {
        return self::pattern($when, "EEE d MMM '·' HH:mm", $locale);
    }

    /** "Tuesday 29 September 2026 · 21:30" — the line on a ticket and in an email. */
    public static function longWhen(?DateTimeInterface $when, ?string $locale = null): string
    {
        return self::pattern($when, "EEEE d MMMM y '·' HH:mm", $locale);
    }

    /** Clock time alone, for "held until". */
    public static function time(?DateTimeInterface $when, ?string $locale = null): string
    {
        return self::pattern($when, 'HH:mm', $locale);
    }

    private static function locale(?string $locale): string
    {
        $locale = Locales::supports($locale) ? $locale : app()->getLocale();

        return Locales::supports($locale) ? $locale : Locales::FALLBACK;
    }
}
