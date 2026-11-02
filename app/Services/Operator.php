<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;

/**
 * The authenticated identity on whose behalf a check-in is performed, passed
 * into {@see TicketCheckInService::resolve()} so the service never reads from
 * `Auth` directly. (Requirements 5.10, 6.1)
 *
 * The web scanner builds one from `Auth::user()`; the API builds one from the
 * token's owning user. Carrying only the operator id and display name keeps the
 * service decoupled from how the caller authenticated — both surfaces supply
 * exactly what a recorded Scan_Event needs (`operator_id`, `operator_name`) and
 * nothing more.
 */
final readonly class Operator
{
    public function __construct(
        public ?int $id,
        public ?string $name,
    ) {}

    /**
     * Build an Operator from an authenticated User. Both fields are nullable so
     * a since-deleted or unresolved user degrades gracefully, matching the web
     * controller's prior `Auth::id()` / `Auth::user()?->name` reads.
     */
    public static function fromUser(?User $user): self
    {
        return new self(
            id: $user?->getKey(),
            name: $user?->name,
        );
    }
}
