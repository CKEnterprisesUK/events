<?php

namespace App\Services\Stripe;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

/**
 * A deterministic, in-memory fake of {@see StripePaymentService} bound in the
 * testing environment. It never talks to Stripe, so automated tests exercise
 * the Platform's onboarding/checkout/refund flows without the live API and
 * without any card data. (design → Stripe boundary / Testing Strategy)
 *
 * The fake records the calls it receives and lets tests steer its behaviour:
 *   - `chargesEnabledFor()` / `enableCharges()` control what
 *     {@see retrieveAccountCapabilities()} reports, so tests can simulate an
 *     account with or without charges enabled. (Requirements 11.3, 11.4)
 *   - `nextAccountId()` pins the account id a fresh onboarding produces, so a
 *     test can assert the stored `stripe_account_id`. (Requirement 11.2)
 */
class FakeStripePaymentService implements StripePaymentService
{
    /**
     * Recorded onboarding-link calls, for assertions.
     *
     * @var list<array{account_id: ?string, return_url: string, refresh_url: string}>
     */
    public array $onboardingLinkCalls = [];

    /**
     * Recorded Checkout Session calls, for assertions.
     *
     * @var list<array<string, mixed>>
     */
    public array $checkoutSessionCalls = [];

    /**
     * Recorded refund calls, for assertions.
     *
     * @var list<array<string, mixed>>
     */
    public array $refundCalls = [];

    /**
     * Per-account charges-enabled flags reported by capability reads. Keyed by
     * account id; defaults to false (a freshly connected account is not yet
     * charges-enabled).
     *
     * @var array<string, bool>
     */
    private array $chargesEnabled = [];

    /**
     * The account id the next fresh onboarding will produce, if pinned.
     */
    private ?string $nextAccountId = null;

    /**
     * When set, the next {@see createCheckoutSession()} throws this to simulate
     * a Stripe session-creation / direct-charge failure, so tests can exercise
     * the failed-payment path without a live API. (Requirement 12.8)
     */
    private ?\Throwable $checkoutSessionFailure = null;

    /**
     * Arrange the fake so the next Checkout Session creation fails, simulating
     * a failed direct charge at the Stripe boundary. The recorded call is NOT
     * appended (the session was never created), mirroring a real failure where
     * no charge is made and no funds move. Returns $this for fluent test setup.
     * (Requirement 12.8)
     */
    public function failNextCheckoutSession(?\Throwable $failure = null): static
    {
        $this->checkoutSessionFailure = $failure
            ?? new \RuntimeException('Stripe Checkout Session creation failed.');

        return $this;
    }

    /**
     * Pin the account id produced by the next onboarding without an existing
     * account. Returns $this for fluent test setup.
     */
    public function nextAccountId(string $accountId): static
    {
        $this->nextAccountId = $accountId;

        return $this;
    }

    /**
     * Set the charges-enabled flag a capability read reports for an account.
     */
    public function setChargesEnabled(string $accountId, bool $enabled = true): static
    {
        $this->chargesEnabled[$accountId] = $enabled;

        return $this;
    }

    /**
     * Whether the fake currently reports charges enabled for an account.
     */
    public function chargesEnabledFor(string $accountId): bool
    {
        return $this->chargesEnabled[$accountId] ?? false;
    }

    public function createOnboardingLink(
        ?string $existingAccountId,
        string $returnUrl,
        string $refreshUrl,
    ): StripeOnboardingLink {
        $accountId = $existingAccountId
            ?? $this->nextAccountId
            ?? 'acct_'.Str::upper(Str::random(16));

        // Consume the pinned id so a subsequent fresh onboarding gets a new one.
        if ($existingAccountId === null) {
            $this->nextAccountId = null;
        }

        $this->onboardingLinkCalls[] = [
            'account_id' => $accountId,
            'return_url' => $returnUrl,
            'refresh_url' => $refreshUrl,
        ];

        return new StripeOnboardingLink(
            accountId: $accountId,
            url: 'https://connect.stripe.test/onboarding/'.$accountId,
        );
    }

    public function retrieveAccountCapabilities(string $accountId): StripeAccountCapabilities
    {
        return new StripeAccountCapabilities(
            accountId: $accountId,
            chargesEnabled: $this->chargesEnabledFor($accountId),
        );
    }

    public function createCheckoutSession(
        string $connectedAccountId,
        string $currency,
        int $amountMinor,
        int $applicationFeeMinor,
        string $successUrl,
        string $cancelUrl,
        array $metadata = [],
    ): StripeCheckoutSession {
        // Simulate a failed direct charge: the session is never created, so no
        // charge is made and no funds move. The failure fires once, then the
        // arrangement is cleared. (Requirement 12.8)
        if ($this->checkoutSessionFailure !== null) {
            $failure = $this->checkoutSessionFailure;
            $this->checkoutSessionFailure = null;

            throw $failure;
        }

        $id = 'cs_test_'.Str::random(20);

        $this->checkoutSessionCalls[] = [
            'connected_account_id' => $connectedAccountId,
            'currency' => $currency,
            'amount_minor' => $amountMinor,
            'application_fee_minor' => $applicationFeeMinor,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => $metadata,
            'id' => $id,
        ];

        return new StripeCheckoutSession(
            id: $id,
            url: 'https://checkout.stripe.test/session/'.$id,
        );
    }

    public function refundCharge(
        string $connectedAccountId,
        string $chargeId,
        int $amountMinor,
    ): StripeRefund {
        $id = 're_test_'.Str::random(20);

        $this->refundCalls[] = [
            'connected_account_id' => $connectedAccountId,
            'charge_id' => $chargeId,
            'amount_minor' => $amountMinor,
            'id' => $id,
        ];

        return new StripeRefund(
            id: $id,
            amountMinor: $amountMinor,
            status: 'succeeded',
        );
    }

    /**
     * Verify a webhook the same way the real SDK does, but deterministically and
     * without any Stripe dependency: the fake signature is
     * `HMAC-SHA256(payload, webhook_secret)`. A request is accepted iff the
     * header is present AND recomputes to the same digest under the configured
     * `stripe.webhook_secret`; otherwise it is rejected. Tests build a valid
     * header with {@see signPayload()}. The verified event's `id`, `type`, and
     * `data.object` are decoded straight from the JSON payload — mirroring what
     * the real boundary returns. (Requirements 19.1, 19.2)
     *
     * @throws WebhookSignatureException
     */
    public function constructWebhookEvent(
        string $payload,
        ?string $signatureHeader,
    ): StripeWebhookEvent {
        $expected = $this->computeSignature($payload);

        // Reject an absent or non-matching signature with no state change — the
        // caller (middleware) turns this into an error response. A constant-time
        // compare mirrors real signature checking. (Requirement 19.2)
        if ($signatureHeader === null || ! hash_equals($expected, $signatureHeader)) {
            throw new WebhookSignatureException('Invalid Stripe webhook signature.');
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($payload, true) ?: [];

        $id = isset($decoded['id']) ? (string) $decoded['id'] : '';
        $type = isset($decoded['type']) ? (string) $decoded['type'] : '';
        $object = $decoded['data']['object'] ?? [];

        return new StripeWebhookEvent(
            id: $id,
            type: $type,
            data: is_array($object) ? $object : [],
        );
    }

    /**
     * Produce the header value that {@see constructWebhookEvent()} will accept
     * for the given raw payload — the test-side counterpart to Stripe signing a
     * webhook with the endpoint's signing secret. Anything else (a tampered
     * payload, a wrong/absent header) is rejected.
     */
    public function signPayload(string $payload): string
    {
        return $this->computeSignature($payload);
    }

    /**
     * The deterministic fake signature for a payload: an HMAC over the raw body
     * keyed by the configured webhook signing secret.
     */
    private function computeSignature(string $payload): string
    {
        $secret = (string) Config::get('stripe.webhook_secret');

        return hash_hmac('sha256', $payload, $secret);
    }
}
