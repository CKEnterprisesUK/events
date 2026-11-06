<?php

namespace App\Support;

/**
 * Safe CSV writing for exports that contain customer-supplied text.
 *
 * Spreadsheet apps treat a cell starting with `=`, `+`, `-`, `@`, tab or CR as a
 * formula, so a customer who books as `=HYPERLINK(...)` could run content when
 * staff open the export. Such text cells are prefixed with an apostrophe, which
 * makes the spreadsheet show them as plain text. Numeric strings (e.g. `-12.50`)
 * are left untouched so negative amounts stay numeric.
 */
class Csv
{
    /**
     * Neutralise a single cell value for CSV output.
     */
    public static function cell(mixed $value): mixed
    {
        if (! is_string($value) || $value === '' || is_numeric($value)) {
            return $value;
        }

        if (in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }

    /**
     * Write one row to an open stream, neutralising every cell.
     *
     * @param  resource  $stream
     * @param  array<int|string, mixed>  $row
     */
    public static function put($stream, array $row): int|false
    {
        return fputcsv($stream, array_map(self::cell(...), $row));
    }
}
