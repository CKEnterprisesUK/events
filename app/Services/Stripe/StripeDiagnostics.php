<?php

namespace App\Services\Stripe;

/**
 * Super_Admin-facing, read-only diagnostics for the Platform's Stripe API
 * credentials. Surfaces a masked view of the configured secret + webhook
 * signing secret and runs a live authentication probe, so an operator can
 * confirm the Platform can talk to Stripe before a Customer ever reaches
 * checkout. Modelled on {@see \App\Services\Mail\Graph\GraphMailDiagnostics}.
 *
 * Nothing here mutates Stripe state; the probe only reads the Platform account
 * through the mockable {@see StripePaymentService} boundary, so automated tests
 * never touch the live API. Secrets are never returned in full — the secret key
 * is masked to a short fingerprint and the webhook secret is a presence flag.
 */
class StripeDiagnostics
{
    public function __construct(
        private readonly StripePaymentService $stripe,
    ) {}

    /**
     * A masked, display-safe summary of the current Stripe configuration for
     * the Settings screen. Presence flags let the operator confirm each value
     * is set without exposing secrets; the key mode (test vs live) is inferred
     * from the secret's prefix and is safe to show.
     *
     * @return array<string, string>
     */
    public function configSummary(): array
    {
        $secret = (string) config('stripe.secret');
        $webhookSecret = (string) config('stripe.webhook_secret');

        return [
            'secret' => $secret !== '' ? 'set' : 'not set',
            'secret_fingerprint' => $this->mask($secret),
            'mode' => $this->mode($secret),
            'webhook_secret' => $webhookSecret !== '' ? 'set' : 'not set',
        ];
    }

    /**
     * Whether the Platform secret key is configured at all. Used to disable the
     * live probe button when there is nothing to check.
     */
    public function isConfigured(): bool
    {
        return (string) config('stripe.secret') !== '';
    }

    /**
     * Run the live probe: verify the Platform's own API credentials against
     * Stripe. Delegates to the mockable boundary and never throws — it returns a
     * structured result the controller flashes as a clear success/failure.
     */
    public function probe(): StripeDiagnosticResult
    {
        if (! $this->isConfigured()) {
            return StripeDiagnosticResult::failure(
                stage: 'config',
                message: 'Stripe is not configured — no secret key is set.',
                hint: 'Set STRIPE_SECRET (and STRIPE_WEBHOOK_SECRET) in the environment, then '
                    .'re-run diagnostics.',
            );
        }

        return $this->stripe->verifyPlatformCredentials();
    }

    /**
     * The key mode inferred from the secret's prefix, for display. Never exposes
     * the secret itself.
     */
    private function mode(string $secret): string
    {
        if ($secret === '') {
            return '—';
        }

        if (str_contains($secret, 'sk_test_')) {
            return 'test';
        }

        if (str_contains($secret, 'sk_live_')) {
            return 'live';
        }

        return 'unknown';
    }

    /**
     * Mask a credential to a short, non-reversible fingerprint (first/last few
     * characters) so the operator can tell whether the configured value matches
     * the Stripe dashboard without the full secret ever leaving the server.
     */
    private function mask(string $value): string
    {
        if ($value === '') {
            return 'not set';
        }

        $length = strlen($value);

        if ($length <= 8) {
            return str_repeat('•', $length);
        }

        return substr($value, 0, 7).str_repeat('•', 6).substr($value, -4);
    }
}
