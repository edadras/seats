<?php

namespace App\Support\Calendar;

use DateTimeInterface;

/**
 * An .ics file with one event in it.
 *
 * Written by hand rather than pulled in, because iCalendar is a small format with three sharp
 * edges, and a library would be a dependency carrying twenty more.
 *
 * The edges, all of which this handles and all of which produce a file that silently fails to
 * import when they are not:
 *
 *   - **CRLF, everywhere.** RFC 5545 says lines end with CRLF; some readers accept a bare LF and
 *     the ones a venue's audience actually uses do not.
 *   - **Folding at 75 octets.** Long lines are continued with CRLF and a single space. The limit
 *     counts octets, not characters, so a Persian event name has to be measured in bytes and cut
 *     between them without splitting one.
 *   - **Escaping.** Backslash, comma and semicolon are escaped; a newline becomes `\n`.
 *
 * Times are written in UTC with a `Z`, which is the one form every reader agrees about.
 */
class IcsFile
{
    private const FOLD_AT = 73; // 75 octets, less the CRLF the folding itself adds.

    /**
     * @param  array<string, string>  $extra  Extra properties, already-formed values.
     */
    public static function event(
        string $uid,
        string $summary,
        DateTimeInterface $starts,
        ?DateTimeInterface $ends = null,
        ?string $location = null,
        ?string $description = null,
        ?string $url = null,
    ): string {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            // Identifies what wrote the file. Readers show it when an import goes wrong.
            'PRODID:-//Seatmap//Event//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:'.$uid,
            'DTSTAMP:'.self::stamp(new \DateTimeImmutable('now')),
            'DTSTART:'.self::stamp($starts),
        ];

        // An event with no stated end is an hour long by convention: a reader given no end shows a
        // point in time, which in a calendar looks like nothing was added.
        $lines[] = 'DTEND:'.self::stamp($ends ?: self::hourAfter($starts));
        $lines[] = 'SUMMARY:'.self::escape($summary);

        if ($location) {
            $lines[] = 'LOCATION:'.self::escape($location);
        }

        if ($description) {
            $lines[] = 'DESCRIPTION:'.self::escape($description);
        }

        if ($url) {
            $lines[] = 'URL:'.self::escape($url);
        }

        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map([self::class, 'fold'], $lines))."\r\n";
    }

    private static function stamp(DateTimeInterface $when): string
    {
        return (new \DateTimeImmutable('@'.$when->getTimestamp()))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Ymd\THis\Z');
    }

    private static function hourAfter(DateTimeInterface $when): DateTimeInterface
    {
        return (new \DateTimeImmutable('@'.$when->getTimestamp()))->modify('+1 hour');
    }

    private static function escape(string $value): string
    {
        return str_replace(
            ["\\", "\n", "\r", ',', ';'],
            ['\\\\', '\\n', '', '\\,', '\;'],
            trim($value)
        );
    }

    /**
     * Fold one line to 75 octets, without cutting a character in half.
     *
     * `mb_strcut` is the whole point: `substr` on UTF-8 splits a Persian letter across two lines
     * and produces a file that shows as mojibake in the one place it matters.
     */
    private static function fold(string $line): string
    {
        if (strlen($line) <= self::FOLD_AT) {
            return $line;
        }

        $folded = [];

        while (strlen($line) > self::FOLD_AT) {
            $piece = mb_strcut($line, 0, self::FOLD_AT, 'UTF-8');
            $folded[] = $piece;
            $line = ' '.substr($line, strlen($piece));
        }

        $folded[] = $line;

        return implode("\r\n", $folded);
    }
}
