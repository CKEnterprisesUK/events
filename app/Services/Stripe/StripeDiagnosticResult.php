<?php

namespace App\Services\Stripe;

/**
 * The outcome of a live, read-only Stripe credential probe run from the
 * Super_Admin Settings screen. It records whether the Platform's own
 * `STRIPE_SECRET` authenticated against the Stripe API, so an operator can tell
 * a missing/invalid key apart from a working one before a Customer ever reaches
 * checkout. Modelled on {@see \App\Services\Mail\Graph\GraphDiagnosticResult}.
 *
 * Nothing that produces this ever mutates Stripe state; the probe only reads
 * the Platform account.
 */
final class StripeDiagnosticResult
{
    /**
     * @param  bool  $ok  overall success of the probe.
     * @param  string  $stage  the stage reached: `config` (key absent) or `auth`.
     * @param  string  $message  a human-readable summary of what happened.
     * @param  string|null  $hint  a targeted next step when something failed.
     * @param  string|null  $accountId  the authenticated Platform account id on success.
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $stage,
        public readonly string $message,
        public readonly ?string $hint = null,
        public readonly ?string $accountId = null,
    ) {}

    public static function ok(string $message, ?string $accountId = null): self
    {
        return new self(true, 'auth', $message, null, $accountId);
    }

    public static function failure(string $stage, string $message, ?string $hint = null): self
    {
        return new self(false, $stage, $message, $hint);
    }
}
