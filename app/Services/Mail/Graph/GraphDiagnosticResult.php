<?php

namespace App\Services\Mail\Graph;

/**
 * The outcome of a live, read-only Graph connectivity probe run from the
 * Super_Admin Settings screen. It records, per stage, whether the app could
 * authenticate ("token") and — optionally — whether it may send as the
 * configured mailbox ("send"), so the operator can tell an authentication
 * problem apart from a mailbox-permission/policy problem while working through
 * the Azure setup steps.
 */
final class GraphDiagnosticResult
{
    /**
     * @param  bool  $ok  overall success of the probe.
     * @param  string  $stage  the stage reached: `token`, `send`, or `config`.
     * @param  string  $message  a human-readable summary of what happened.
     * @param  string|null  $hint  a targeted next step when something failed.
     * @param  int|null  $status  the HTTP status of the failing call, if any.
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $stage,
        public readonly string $message,
        public readonly ?string $hint = null,
        public readonly ?int $status = null,
    ) {}

    public static function ok(string $stage, string $message): self
    {
        return new self(true, $stage, $message);
    }

    public static function failure(string $stage, string $message, ?string $hint = null, ?int $status = null): self
    {
        return new self(false, $stage, $message, $hint, $status);
    }
}
