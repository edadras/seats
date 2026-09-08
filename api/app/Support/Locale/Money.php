<?php

namespace App\Support\Locale;

use NumberFormatter;

/**
 * Money, written the way the reader reads it — in the currency the event charges (ADR-0005 §5).
 *
 * Two different things are decided here and they must not be confused:
 *
 *   the **currency** comes from the event, because that is what the buyer is charged;
 *   the **formatting** comes from the reader, because that is what they can read.
 *
 * A Persian reader looking at a Berlin show sees euros written the Persian way. Getting this
 * backwards does not merely look wrong, it misstates a price, which is why this is a function with
 * both arguments rather than string concatenation at a hundred call sites.
 */
final class Money
{
    /**
     * Currencies whose minor unit is not 1/100.
     *
     * Amounts are stored as integers in the currency's minor unit. For most currencies that is
     * cents; for these it is not, and dividing by 100 would be wrong by two orders of magnitude.
     */
    private const EXPONENTS = [
        'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'IRR' => 0, 'ISK' => 0,
        'JPY' => 0, 'KMF' => 0, 'KRW' => 0, 'PYG' => 0, 'RWF' => 0, 'UGX' => 0,
        'UYI' => 0, 'VND' => 0, 'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3,
    ];

    /** How many decimal places this currency's minor unit implies. */
    public static function exponent(string $currency): int
    {
        return self::EXPONENTS[strtoupper($currency)] ?? 2;
    }

    /** Minor units to a decimal amount: 12345 EUR-cents becomes 123.45. */
    public static function toDecimal(int $minorUnits, string $currency): float
    {
        return $minorUnits / (10 ** self::exponent($currency));
    }

    /** And back, rounded rather than truncated: 0.1 + 0.2 must not become 29 cents. */
    public static function toMinorUnits(float $amount, string $currency): int
    {
        return (int) round($amount * (10 ** self::exponent($currency)));
    }

    /**
     * @param int $minorUnits The stored amount, in the currency's smallest unit.
     */
    public static function format(int $minorUnits, string $currency, ?string $locale = null): string
    {
        $locale = Locales::supports($locale) ? $locale : app()->getLocale();
        $locale = Locales::supports($locale) ? $locale : Locales::FALLBACK;
        $currency = strtoupper($currency);

        $formatter = new NumberFormatter(Locales::icu($locale), NumberFormatter::CURRENCY);
        $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, self::exponent($currency));

        $formatted = $formatter->formatCurrency(self::toDecimal($minorUnits, $currency), $currency);

        // ICU can fail on an unknown code. Better a plain "1234.00 XYZ" than the word "false" on
        // somebody's checkout page.
        return false === $formatted
            ? number_format(self::toDecimal($minorUnits, $currency), self::exponent($currency)).' '.$currency
            : $formatted;
    }

    /** The same amount with no currency mark, for a column that already has one in its header. */
    public static function formatAmount(int $minorUnits, string $currency, ?string $locale = null): string
    {
        $locale = Locales::supports($locale) ? $locale : app()->getLocale();
        $locale = Locales::supports($locale) ? $locale : Locales::FALLBACK;

        $formatter = new NumberFormatter(Locales::icu($locale), NumberFormatter::DECIMAL);
        $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, self::exponent($currency));

        return $formatter->format(self::toDecimal($minorUnits, strtoupper($currency)));
    }

    /** A plain number — a seat count, a percentage — in the reader's digits. */
    public static function number(int|float $value, ?string $locale = null, int $decimals = 0): string
    {
        $locale = Locales::supports($locale) ? $locale : app()->getLocale();
        $locale = Locales::supports($locale) ? $locale : Locales::FALLBACK;

        $formatter = new NumberFormatter(Locales::icu($locale), NumberFormatter::DECIMAL);
        $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, $decimals);

        return $formatter->format($value);
    }
}
