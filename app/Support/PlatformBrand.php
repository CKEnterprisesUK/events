<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Single source of truth for the platform operator's branding line that goes
 * on every export the platform produces (ticket PDF, sales/orders/attendance
 * CSV + XLSX reports, GDPR export, etc.).
 *
 * This is the PLATFORM brand ("Events by CK Enterprises UK"), deliberately
 * distinct from a selling Company's own branding (logo / colours / name) that
 * already appears on tickets and storefronts. Every export carries this line so
 * a downloaded file is always attributable back to the platform.
 *
 * The on-page logo is only rendered where it is cheap and reliable (the HTML
 * ticket PDF). Spreadsheet/CSV exports get the wording and link only, because
 * embedding an image in a raw CSV/XLSX is brittle and offers no real value.
 */
final class PlatformBrand
{
    /** The platform wordmark shown on every export. */
    public const NAME = 'Events by CK Enterprises UK';

    /**
     * The home URL of the platform, used as the clickable/printed link beneath
     * the wordmark. Falls back to the configured app URL.
     */
    public static function url(): string
    {
        return rtrim((string) config('app.url', 'https://events.ckenterprises.co.uk'), '/');
    }

    /**
     * The one-line branding string for plain-text contexts (CSV cells, file
     * footers): wordmark + link, e.g.
     * "Events by CK Enterprises UK — https://events.ckenterprises.co.uk".
     */
    public static function line(): string
    {
        return self::NAME.' — '.self::url();
    }
}
