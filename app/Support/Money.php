<?php

namespace App\Support;

/**
 * Formatting helpers for money stored as integer minor currency units.
 *
 * The Platform's base/reporting currency is GBP, so the super-admin surface
 * presents every amount in pounds with a leading £ and thousands separators.
 * Keeping this in one place means the admin portal formats money identically
 * everywhere, rather than each view rolling its own closure.
 */
final class Money
{
    /**
     * Format integer minor units (pence) as a GBP string, e.g. 123456 → "£1,234.56".
     */
    public static function gbp(int $minor): string
    {
        return '£'.number_format($minor / 100, 2);
    }

    /**
     * Format minor units in an arbitrary currency, falling back to the ISO code
     * when the symbol is unknown, e.g. (4999, 'USD') → "$49.99". Used where a
     * specific Company's own currency is the right thing to show.
     */
    public static function format(int $minor, string $currency = 'GBP'): string
    {
        $symbols = ['GBP' => '£', 'USD' => '$', 'EUR' => '€'];
        $symbol = $symbols[strtoupper($currency)] ?? (strtoupper($currency).' ');

        return $symbol.number_format($minor / 100, 2);
    }
}
