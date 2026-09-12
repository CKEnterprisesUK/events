<?php

namespace App\Services\Stripe;

use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;
use Throwable;
use UnexpectedValueException;

/**
 * The production {@see StripePaymentService}, backed by the official
 * `stripe/stripe-php` SDK. Bound in non-testing environments; tests always use
 * {@see FakeStripePaymentService} so no live Stripe call is ever made in the
 * suite. (design → Stripe boundary)
 *
 * Connect Standard onboarding is implemented with Account Links (Requirement
 * 11.1): a Standard connected account is created on first onboarding and reused
 * on subsequent attempts, and an Account Link URL is returned for the Owner to
 * complete onboarding. Capability reads (Requirement 11.3) come from the
 * account's `charges_enabled` flag. Checkout Sessions are created as direct
 * charges on the connected account with `application_fee_amount` (Requirement
 * 12.1), and refunds are issued on the connected account (Requirement 17.2).
 */
class StripePaymentServiceStripeSdk implements StripePaymentService
{
    public function __construct(
        private readonly StripeClient $client,
        private readonly string $webhookSecret,
    ) {}

    public function createOnboardingLink(
        ?string $existingAccountId,
        string $returnUrl,
        string $refreshUrl,
    ): StripeOnboardingLink {
        return $this->withoutStripeNoticeEscalation(function () use ($existingAccountId, $returnUrl, $refreshUrl): StripeOnboardingLink {
            $accountId = $existingAccountId;

            if ($accountId === null) {
                $account = $this->client->accounts->create([
                    'type' => 'standard',
                ]);
                $accountId = $account->id;
            }

            $link = $this->client->accountLinks->create([
                'account' => $accountId,
                'return_url' => $returnUrl,
                'refresh_url' => $refreshUrl,
                'type' => 'account_onboarding',
            ]);

            return new StripeOnboardingLink(
                accountId: $accountId,
                url: $link->url,
            );
        });
    }

    public function retrieveAccountCapabilities(string $accountId): StripeAccountCapabilities
    {
        return $this->withoutStripeNoticeEscalation(function () use ($accountId): StripeAccountCapabilities {
            $account = $this->client->accounts->retrieve($accountId);

            return new StripeAccountCapabilities(
                accountId: $accountId,
                chargesEnabled: (bool) ($account->charges_enabled ?? false),
            );
        });
    }

    public function createCheckoutSession(
        string $connectedAccountId,
        string $currency,
        int $amountMinor,
        int $applicationFeeMinor,
        string $successUrl,
        string $cancelUrl,
        array $metadata = [],
        ?string $customerEmail = null,
    ): StripeCheckoutSession {
        // Direct charge on the connected account: the request is made on the
        // connected account (Stripe-Account header) and the Platform takes its
        // cut via `application_fee_amount`. (Requirement 12.1)
        return $this->withoutStripeNoticeEscalation(function () use (
            $connectedAccountId,
            $currency,
            $amountMinor,
            $applicationFeeMinor,
            $successUrl,
            $cancelUrl,
            $metadata,
            $customerEmail,
        ): StripeCheckoutSession {
            $params = [
                'mode' => 'payment',
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'metadata' => $metadata,
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => $currency,
                        'unit_amount' => $amountMinor,
                        'product_data' => [
                            'name' => $metadata['order_reference'] ?? 'Order',
                        ],
                    ],
                ]],
                'payment_intent_data' => [
                    'application_fee_amount' => $applicationFeeMinor,
                ],
            ];

            // Pre-fill the email on the hosted Checkout page so the Customer
            // doesn't have to re-enter the address they already gave us. Only
            // set it when present — Stripe rejects a null/empty `customer_email`.
            if ($customerEmail !== null && $customerEmail !== '') {
                $params['customer_email'] = $customerEmail;
            }

            $session = $this->client->checkout->sessions->create($params, [
                'stripe_account' => $connectedAccountId,
            ]);

            return new StripeCheckoutSession(
                id: $session->id,
                url: $session->url,
            );
        });
    }

    public function refundCharge(
        string $connectedAccountId,
        string $chargeId,
        int $amountMinor,
    ): StripeRefund {
        return $this->withoutStripeNoticeEscalation(function () use ($connectedAccountId, $chargeId, $amountMinor): StripeRefund {
            $refund = $this->client->refunds->create([
                'charge' => $chargeId,
                'amount' => $amountMinor,
            ], [
                'stripe_account' => $connectedAccountId,
            ]);

            return new StripeRefund(
                id: $refund->id,
                amountMinor: (int) $refund->amount,
                status: (string) $refund->status,
            );
        });
    }

    public function retrieveChargeFee(
        string $connectedAccountId,
        string $paymentIntentId,
    ): ?StripeChargeFee {
        // Read on the connected account (Stripe-Account header) because the
        // charge and its balance transaction — where Stripe records the exact
        // processing fee it deducted — live on the connected account, not the
        // Platform account (direct charges). We expand the charge and its
        // balance transaction so the fee comes back in a single round-trip.
        // (Truthful-payout feature; Stripe: "expand latest_charge.balance_transaction")
        return $this->withoutStripeNoticeEscalation(function () use ($connectedAccountId, $paymentIntentId): ?StripeChargeFee {
            try {
                $intent = $this->client->paymentIntents->retrieve(
                    $paymentIntentId,
                    ['expand' => ['latest_charge.balance_transaction']],
                    ['stripe_account' => $connectedAccountId],
                );
            } catch (Throwable $e) {
                // A missing/unavailable fee must never fail the webhook: log and
                // return null so the caller leaves stripe_fee_minor unset and can
                // retry on a later delivery. (Interface contract: never throw.)
                Log::warning('Could not retrieve Stripe charge fee for payment intent.', [
                    'payment_intent' => $paymentIntentId,
                    'connected_account' => $connectedAccountId,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }

            $charge = $intent->latest_charge ?? null;

            // The SDK returns the id (string) when not expanded, or the object
            // when expanded. We asked to expand, so expect the object; guard the
            // string case so a partial response degrades to "not yet available".
            if (! is_object($charge)) {
                return null;
            }

            $balanceTransaction = $charge->balance_transaction ?? null;

            // The balance transaction (and thus the fee) is only present once the
            // PaymentIntent has succeeded and been captured. Absent => not ready.
            if (! is_object($balanceTransaction) || ! isset($balanceTransaction->fee)) {
                return null;
            }

            return new StripeChargeFee(
                chargeId: (string) $charge->id,
                feeMinor: (int) $balanceTransaction->fee,
                currency: (string) ($balanceTransaction->currency ?? ''),
            );
        });
    }

    public function constructWebhookEvent(
        string $payload,
        ?string $signatureHeader,
    ): StripeWebhookEvent {
        // Stripe's SDK verifies the HMAC signature (and rejects a missing or
        // malformed header) against the webhook signing secret, throwing on any
        // mismatch. We translate both failure modes into our boundary exception
        // so the middleware need not know about SDK types. (Requirement 19.1, 19.2)
        try {
            $event = Webhook::constructEvent(
                $payload,
                (string) $signatureHeader,
                $this->webhookSecret,
            );
        } catch (SignatureVerificationException|UnexpectedValueException $e) {
            throw new WebhookSignatureException($e->getMessage(), 0, $e);
        }

        $object = $event->data?->object ?? null;

        return new StripeWebhookEvent(
            id: (string) $event->id,
            type: (string) $event->type,
            data: $object !== null ? $object->toArray() : [],
        );
    }

    public function verifyPlatformCredentials(): StripeDiagnosticResult
    {
        // A read-only, authenticated call: retrieving the Platform's own account
        // is the cheapest way to prove STRIPE_SECRET is present and valid. It
        // mutates nothing. Wrapped so the advisory Accounts-v2 notice can't
        // escalate a successful call into a 500 (mirrors every other SDK call).
        return $this->withoutStripeNoticeEscalation(function (): StripeDiagnosticResult {
            try {
                $account = $this->client->accounts->retrieve();
            } catch (AuthenticationException $e) {
                // The key was rejected by Stripe (missing, malformed, revoked,
                // or the wrong mode). This is the decisive credential failure.
                return StripeDiagnosticResult::failure(
                    stage: 'auth',
                    message: 'Stripe rejected the API secret key: '.$e->getMessage(),
                    hint: 'Check STRIPE_SECRET in the environment. It must be the Platform '
                        .'secret key (starts with "sk_live_" in production or "sk_test_" in test '
                        .'mode) and must not be revoked.',
                );
            } catch (ApiErrorException $e) {
                // Authenticated far enough to reach the API but Stripe returned
                // another error (rate limit, transient, permissions). Report it
                // plainly rather than as a flat credential failure.
                return StripeDiagnosticResult::failure(
                    stage: 'auth',
                    message: 'Stripe returned an error while verifying the account: '.$e->getMessage(),
                    hint: 'The secret key authenticated but the request did not complete. This is '
                        .'usually transient — try again shortly.',
                );
            } catch (Throwable $e) {
                // Network/DNS/TLS never reached Stripe — not an auth problem.
                return StripeDiagnosticResult::failure(
                    stage: 'auth',
                    message: 'Could not reach Stripe: '.$e->getMessage(),
                    hint: 'Check outbound network access to api.stripe.com.',
                );
            }

            $mode = str_contains((string) config('stripe.secret'), 'sk_test_')
                ? 'test'
                : 'live';

            return StripeDiagnosticResult::ok(
                message: 'Authenticated to Stripe as account '.$account->id.' ('.$mode.' mode). '
                    .'The Platform secret key is valid.',
                accountId: (string) $account->id,
            );
        });
    }

    /**
     * Run a Stripe SDK call while preventing an informational `stripe-notice`
     * response header from crashing the request.
     *
     * On otherwise-successful (HTTP 2xx) responses the SDK surfaces any
     * `stripe-notice` header — currently the "build on Accounts v2"
     * recommendation for v1 account creation — via `trigger_error(..., E_USER_WARNING)`.
     * Laravel's error handler promotes that warning to an `ErrorException`, so a
     * successful API call (e.g. a connected account is actually created) still
     * returns a 500. We install a scoped error handler that swallows only that
     * specific Stripe notice and defers everything else to the previous handler,
     * then restore it immediately in a `finally` so error handling elsewhere is
     * unchanged. This is intentionally not a signal error: the recommendation is
     * advisory and the v1 flow remains supported.
     *
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    private function withoutStripeNoticeEscalation(callable $callback): mixed
    {
        set_error_handler(
            static function (int $severity, string $message, ?string $file = null, ?int $line = null): bool {
                // Swallow only Stripe's advisory Accounts v2 notice; let any
                // other warning fall through to the default handler.
                return $severity === E_USER_WARNING
                    && str_contains($message, 'Accounts v2');
            },
            E_USER_WARNING,
        );

        try {
            return $callback();
        } finally {
            restore_error_handler();
        }
    }
}
