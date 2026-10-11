<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * An optional, inclusive `from`/`to` date range for the reports, parsed from
 * request query parameters. Each bound is a `Y-m-d` value; an absent or
 * malformed value becomes null (no bound), so a bad param simply widens the
 * range rather than erroring. When both are present and reversed they are
 * swapped so the range is always well-ordered.
 *
 * `from` is normalised to start-of-day and `to` to end-of-day so they can bound
 * a timestamp column (e.g. Orders' `created_at`) inclusively. A range with both
 * bounds null covers all time.
 *
 * This mirrors the parsing the company report (ReportController) already used
 * inline, extracted here so the per-event report and questions report apply the
 * exact same rules — one definition of "the report's date range" across every
 * surface.
 */
final class ReportDateRange
{
    private function __construct(
        public readonly ?Carbon $from,
        public readonly ?Carbon $to,
    ) {}

    /**
     * Build a range from a request's `from`/`to` query parameters.
     */
    public static function fromRequest(Request $request): self
    {
        $from = self::parse($request->query('from'));
        $to = self::parse($request->query('to'));

        if ($from !== null && $to !== null && $from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return new self($from?->startOfDay(), $to?->endOfDay());
    }

    /**
     * An open (all-time) range.
     */
    public static function none(): self
    {
        return new self(null, null);
    }

    /**
     * Whether either bound is set (i.e. the figures are narrowed).
     */
    public function isBounded(): bool
    {
        return $this->from !== null || $this->to !== null;
    }

    /**
     * The `from` bound as a `Y-m-d` string (for form values / links), or null.
     */
    public function fromDate(): ?string
    {
        return $this->from?->toDateString();
    }

    /**
     * The `to` bound as a `Y-m-d` string (for form values / links), or null.
     */
    public function toDate(): ?string
    {
        return $this->to?->toDateString();
    }

    /**
     * A human-readable label for the covered period, for headings/exports.
     */
    public function label(): string
    {
        if (! $this->isBounded()) {
            return 'All time';
        }

        $fmt = fn (?Carbon $d): string => $d?->format('j M Y') ?? '…';

        if ($this->from !== null && $this->to === null) {
            return 'From '.$fmt($this->from);
        }

        if ($this->from === null && $this->to !== null) {
            return 'Up to '.$fmt($this->to);
        }

        return $fmt($this->from).' – '.$fmt($this->to);
    }

    /**
     * Parse a `Y-m-d` query value into a Carbon date, or null when absent or
     * malformed.
     */
    private static function parse(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', trim($value)) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
