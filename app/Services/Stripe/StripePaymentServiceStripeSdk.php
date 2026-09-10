<?php

namespace App\Services\Stripe;

use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;
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
