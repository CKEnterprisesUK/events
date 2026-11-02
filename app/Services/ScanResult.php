<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Carbon;

/**
 * The structured outcome of a check-in attempt — the single Scan_Outcome
 * contract both the web scanner and the API read. (Requirements 5.3–5.8, 6.2)
 *
 * This replaces the loose array the web `ScanController` used to return from
 * its private `resolve()`; the shape is identical so callers keep reading the
 * same fields:
 *
 *   - `status`        one of checked_in|already_scanned|rejected|failed|invalid|unreadable
 *   - `message`       the human-facing message the door surface shows
 *   - `order`         the resolved Order, when one was found (null for unreadable/invalid/rejected)
 *   - `scannedAt`     the check-in instant (the winner's, for already_scanned)
 *   - `scannedByName` the name of the operator who checked it in (already_scanned only)
 *   - `breakdown`     the Ticket_Type + quantity rows for a successful check-in
 *
 * Immutable: a result is built once by {@see TicketCheckInService} and read by
 * the caller. The named constructors mirror each branch of the decision tree so
 * the outcomes stay easy to assert directly in tests.
 */
final readonly class ScanResult
{
    public const STATUS_CHECKED_IN = 'checked_in';

    public const STATUS_ALREADY_SCANNED = 'already_scanned';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_FAILED = 'failed';

    public const STATUS_INVALID = 'invalid';

    public const STATUS_UNREADABLE = 'unreadable';

    /**
     * @param  'checked_in'|'already_scanned'|'rejected'|'failed'|'invalid'|'unreadable'  $status
     * @param  list<array{name: string, quantity: int}>  $breakdown
     */
    public function __construct(
        public string $status,
        public string $message,
        public ?Order $order = null,
        public ?Carbon $scannedAt = null,
        public ?string $scannedByName = null,
        public array $breakdown = [],
    ) {}

    /**
     * A code that could not be read/decoded into a usable reference at all.
     * (Requirement 5.3)
     */
    public static function unreadable(): self
    {
        return new self(
            status: self::STATUS_UNREADABLE,
            message: 'Unreadable code. Please try scanning again.',
        );
    }

    /**
     * A decodable payload whose recomputed HMAC did not match the supplied
     * token — a tampered or foreign token. (Requirement 5.4)
     */
    public static function invalid(): self
    {
        return new self(
            status: self::STATUS_INVALID,
            message: 'Invalid code. This ticket could not be verified.',
        );
    }

    /**
     * A reference that matches no Order within the tenant scope (unknown or
     * another Company's). (Requirement 5.5)
     */
    public static function rejected(): self
    {
        return new self(
            status: self::STATUS_REJECTED,
            message: 'Ticket not found.',
        );
    }

    /**
     * A valid Order that belongs to a DIFFERENT Event of the same Company than
     * the one the operator is scanning for. Reported as `rejected` (the client
     * already handles that status) but with a message that makes the mismatch
     * clear so the operator knows it is the wrong door, not an unknown ticket.
     */
    public static function wrongEvent(Order $order): self
    {
        return new self(
            status: self::STATUS_REJECTED,
            message: 'This ticket is for a different event.',
            order: $order,
        );
    }

    /**
     * A resolved Order that is not confirmed (reserved, expired, cancelled,
     * refunded, disputed, voided). (Requirement 5.6)
     */
    public static function failed(Order $order): self
    {
        return new self(
            status: self::STATUS_FAILED,
            message: 'Failed. This ticket is no longer valid.',
            order: $order,
        );
    }

    /**
     * An Order already checked in earlier, carrying the original scanned_at and
     * the name of the operator who checked it in. (Requirement 5.7)
     *
     * @param  list<array{name: string, quantity: int}>  $breakdown
     */
    public static function alreadyScanned(Order $order, Carbon $scannedAt, ?string $scannedByName): self
    {
        return new self(
            status: self::STATUS_ALREADY_SCANNED,
            message: 'Already scanned.',
            order: $order,
            scannedAt: $scannedAt,
            scannedByName: $scannedByName,
        );
    }

    /**
     * A confirmed, previously-unscanned Order just checked in atomically,
     * carrying its Ticket_Type + quantity breakdown. (Requirement 5.8)
     *
     * @param  list<array{name: string, quantity: int}>  $breakdown
     */
    public static function checkedIn(Order $order, Carbon $scannedAt, array $breakdown): self
    {
        return new self(
            status: self::STATUS_CHECKED_IN,
            message: 'Checked in.',
            order: $order,
            scannedAt: $scannedAt,
            breakdown: $breakdown,
        );
    }

    /**
     * The total number of tickets represented by this result's breakdown — the
     * figure the session recap and the ScanEvent record as `tickets`.
     */
    public function ticketCount(): int
    {
        $total = 0;

        foreach ($this->breakdown as $row) {
            $total += $row['quantity'];
        }

        return $total;
    }
}
