<?php

namespace App\Services\Stripe;

/**
 * The Platform's boundary to Stripe. Every Stripe interaction goes through this
 * interface so the real SDK-backed implementation is used in production and a
 * deterministic fake is bound in tests — Stripe is ALWAYS mocked in automated
 * tests and Customer card data never touches the Platform. (design → Stripe
 * boundary / Testing Strategy)
 *
 * Responsibilities (design → StripePaymentService):
 *   - Connect Standard onboarding: produce an onboarding URL the Owner is sent
 *     to (OAuth / Account Links) and, on return, resolve the connected account.
 *     (Requirements 11.1, 11.2)
 *   - Read a connected account's capabilities to decide whether the Company may
 *     sell paid tickets (charges enabled). (Requirements 11.3, 11.5)
 *   - Create Checkout Sessions as direct charges with `application_fee_amount`
 *     (used by the checkout flow in task 15). (Requirement 12.1)
 *   - Issue refunds on the connected account (used by refunds in task 20).
 *     (Requirement 17.2)
 *   - Verify the Platform's own API credentials for Super_Admin diagnostics.
 */
interface StripePaymentService
{
    /**
     * Begin Connect Standard onboarding for a Company and return the URL the
     * Owner should be redirected to in order to connect (or finish connecting)
     * their Stripe account. (Requirement 11.1)
     *
     * @param  string|null  $existingAccountId  A previously stored connected
     *                                          account id, if the Company has
     *                                          already begun onboarding, so the
     *                                          flow can resume the same account.
     * @param  string  $returnUrl  Where Stripe returns the Owner on completion.
     * @param  string  $refreshUrl  Where Stripe returns the Owner if the link
     *                              expired and must be regenerated.
     */
    public function createOnboardingLink(
        ?string $existingAccountId,
        string $returnUrl,
        string $refreshUrl,
    ): StripeOnboardingLink;

    /**
     * Read the current capabilities of a connected account, principally whether
     * charges are enabled. Called on return from onboarding to persist the
     * Company's connected status. (Requirements 11.2, 11.3)
     */
    public function retrieveAccountCapabilities(string $accountId): StripeAccountCapabilities;

    /**
     * Create a Stripe Checkout Session as a direct charge on the connected
     * account, charging `$amountMinor` and taking `$applicationFeeMinor` as the
     * Platform's `application_fee_amount`. Consumed by the checkout flow in a
     * later task (task 15). (Requirement 12.1)
     *
     * @param  array<string, mixed>  $metadata  Order metadata (e.g. order reference).
     * @param  string|null  $customerEmail  The Customer's email, pre-filled on
     *                                       the hosted Checkout page so they do
     *                                       not have to type it again. Passing
     *                                       null leaves the email field blank.
     */
    public function createCheckoutSession(
        string $connectedAccountId,
        string $currency,
        int $amountMinor,
        int $applicationFeeMinor,
        string $successUrl,
        string $cancelUrl,
        array $metadata = [],
        ?string $customerEmail = null,
    ): StripeCheckoutSession;

    /**
     * Issue a refund against a charge on a connected account. Consumed by the
     * refund flow in a later task (task 20). (Requirement 17.2)
     */
    public function refundCharge(
        string $connectedAccountId,
        string $chargeId,
        int $amountMinor,
    ): StripeRefund;

    /**
     * Read the ACTUAL card-processing fee Stripe took for a completed payment on
     * a connected account. Given the connected account and the PaymentIntent id
     * from a completed Checkout Session, this resolves the underlying charge and
     * its Balance Transaction — where Stripe records the exact `fee` it deducted
     * inside the connected account — and returns the fee in integer minor units
     * along with the resolved charge id.
     *
     * This is Stripe's own fee, distinct from the Platform's `application_fee_amount`.
     * It lets the dashboard and reports show a truthful net payout, and stays
     * accurate automatically if Stripe changes its pricing — nothing is hardcoded.
     *
     * Returns null when the fee cannot be determined (e.g. the PaymentIntent has
     * no settled charge yet, or the balance transaction is not available), so the
     * caller can leave the Order's `stripe_fee_minor` unset and retry later rather
     * than record a wrong value. Implementations must not throw for a missing fee.
     * (Truthful-payout feature)
     */
    public function retrieveChargeFee(
        string $connectedAccountId,
        string $paymentIntentId,
    ): ?StripeChargeFee;

    /**
     * Verify an incoming webhook request and construct the event from it. The
     * signature carried in `$signatureHeader` (Stripe's `Stripe-Signature`
     * header) is checked against the raw `$payload` under the webhook signing
     * secret; only if it matches is the decoded {@see StripeWebhookEvent}
     * returned. Verification runs here — at the Stripe boundary — so the webhook
     * middleware/controller never touches the live SDK directly and the whole
     * path is exercised with the fake in tests. (Requirements 19.1, 19.2)
     *
     * @param  string  $payload  The raw, unparsed request body.
     * @param  string|null  $signatureHeader  The `Stripe-Signature` header, if present.
     *
     * @throws WebhookSignatureException When the signature is absent, malformed,
     *                                   or does not match the payload.
     */
    public function constructWebhookEvent(
        string $payload,
        ?string $signatureHeader,
    ): StripeWebhookEvent;

    /**
     * Verify that the Platform's own Stripe API credentials are valid by making
     * a lightweight, read-only, authenticated call to Stripe (retrieving the
     * Platform account). Used by the Super_Admin Settings diagnostics so an
     * invalid or missing `STRIPE_SECRET` is surfaced proactively rather than
     * only when a Customer hits checkout. Never mutates any Stripe state.
     *
     * Returns a structured result (never throws) so the caller can render a
     * clear success/failure with a targeted hint.
     */
    public function verifyPlatformCredentials(): StripeDiagnosticResult;
}
