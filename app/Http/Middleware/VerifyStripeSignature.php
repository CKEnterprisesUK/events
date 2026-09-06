<?php

namespace App\Http\Middleware;

use App\Http\Controllers\WebhookController;
use App\Services\Stripe\StripePaymentService;
use App\Services\Stripe\StripeWebhookEvent;
use App\Services\Stripe\WebhookSignatureException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies the Stripe webhook signature before any processing runs. The raw
 * request body and the `Stripe-Signature` header are handed to the Stripe
 * boundary ({@see StripePaymentService::constructWebhookEvent()}), which checks
 * the signature against the webhook signing secret. (Requirement 19.1)
 *
 * On a valid signature the verified {@see StripeWebhookEvent} is stashed on the
 * request attributes so the {@see WebhookController} can
 * use it without re-parsing or re-verifying. On an absent, malformed, or
 * mismatched signature the request is rejected with a 400 and NO state change
 * occurs — the controller (and therefore dedupe/enqueue) never runs.
 * (Requirement 19.2)
 *
 * Verification lives at the Stripe boundary so this middleware never touches the
 * live SDK directly; in tests the fake boundary verifies deterministically.
 */
class VerifyStripeSignature
{
    /**
     * The request attribute key under which the verified event is stashed.
     */
    public const EVENT_ATTRIBUTE = 'stripe_webhook_event';

    public function __construct(private readonly StripePaymentService $stripe) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $event = $this->stripe->constructWebhookEvent(
                $request->getContent(),
                $request->header('Stripe-Signature'),
            );
        } catch (WebhookSignatureException) {
            // Reject invalid/absent signatures with an error and no processing.
            // (Requirement 19.2)
            return response()->json(['error' => 'Invalid signature.'], 400);
        }

        $request->attributes->set(self::EVENT_ATTRIBUTE, $event);

        return $next($request);
    }
}
