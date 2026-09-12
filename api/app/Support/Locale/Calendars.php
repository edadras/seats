<?php

namespace App\Support\Locale;

/**
 * Which calendar a date is written in.
 *
 * Until now this followed the reader's language: Persian got the Persian calendar, everyone else
 * Gregorian. That is a sensible default and it is not the same question as the one a venue actually
 * has. An Iranian theatre whose page is in English still programmes its season in Jalali and prints
 * ۱۴۰۵ on the ticket; a venue in Dubai reading Persian may keep its books in Gregorian and want the
 * door list to match. The language is about the reader, the calendar is about the organisation.
 *
 * So the calendar is a setting, with three values, and `auto` is the old behaviour kept as a
 * deliberate choice rather than as the only one:
 *
 *   `auto`      follow the reader's language — Persian sees Jalali, everyone else Gregorian
 *   `persian`   Jalali for every reader, in every language
 *   `gregory`   Gregorian for every reader, Persian included
 *
 * **One place decides, and everything follows.** {@see Dates} formats with it, and
 * {@see Locales::icu()} puts it into the locale string that is handed to the browser — so the
 * panel's own JavaScript, which formats dates with `Intl`, changes with it too and without knowing
 * this class exists.
 *
 * **The stored instant never changes.** Everything in this platform is UTC in the database and a
 * wall clock in the venue's own zone on the way out. A calendar is a way of *writing* a moment
 * down; it is not a different moment, and nothing here converts anything but the writing.
 */
final class Calendars
{
    /** @var list<string> */
    public const CHOICES = ['auto', 'persian', 'gregory'];

    /**
     * The choice in force for this request, when something has made one.
     *
     * Request-scoped and set by whatever knows whose page this is: a site's own middleware for a
     * buyer, the account for a member of staff. Null is "nobody said", which is `auto`.
     */
    private static ?string $chosen = null;

    /** Whatever knows whose page this is says so here. Anything unrecognised is `auto`. */
    public static function use(?string $choice): void
    {
        self::$chosen = self::clean($choice);
    }

    /** Between two requests in one process — a queue worker sends mail for many accounts. */
    public static function forget(): void
    {
        self::$chosen = null;
    }

    public static function chosen(): string
    {
        return self::$chosen ?? 'auto';
    }

    /** What a date should actually be written in, for a reader of this language. */
    public static function current(?string $locale = null): string
    {
        $choice = self::chosen();

        if ('auto' !== $choice) {
            return $choice;
        }

        return Locales::info($locale ?: app()->getLocale())['calendar'];
    }

    /** A value from outside — a form, a stored column — reduced to one of the three. */
    public static function clean(?string $choice): ?string
    {
        $choice = is_string($choice) ? strtolower(trim($choice)) : '';

        // 'jalali' and 'shamsi' are what people call it; ICU calls it 'persian' and so does the
        // rest of this file. Accepted on the way in so a hand-written API call means what it says.
        $choice = match ($choice) {
            'jalali', 'shamsi', 'solar' => 'persian',
            'gregorian' => 'gregory',
            default => $choice,
        };

        return in_array($choice, self::CHOICES, true) ? $choice : null;
    }

    /**
     * Run one piece of work in a particular calendar, then put it back.
     *
     * For the work that is not a request: a ticket rendered by a queue worker is for one site, and
     * the next job in the same process is for another.
     */
    public static function runAs(?string $choice, callable $work): mixed
    {
        $before = self::$chosen;

        self::use($choice);

        try {
            return $work();
        } finally {
            self::$chosen = $before;
        }
    }
}
