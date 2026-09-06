<?php

namespace App\Http\Controllers;

use App\Http\Middleware\VerifyStripeSignature;
use App\Jobs\ProcessWebhookJob;
use App\Services\Stripe\StripeWebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Receives Stripe webhooks at the fixed Platform endpoint (`POST
 * /stripe/webhook`), which sits OUTSIDE the tenant group — Stripe posts to one
 * URL with no company slug, no auth, and no CSRF. (Design → WebhookController,
 * Requirements 19.1–19.5)
 *
 * The {@see VerifyStripeSignature} middleware has already verified the signature
 * against the webhook signing secret and stashed the verified
 * {@see StripeWebhookEvent} on the request; an invalid/absent signature never
 * reaches here (it was rejected with an error and no state change).
 *
 * This action does the minimum synchronously so it can return a 2xx quickly:
 * it hands the verified event to a {@see ProcessWebhookJob} on the DB queue for
 * asynchronous handling (dedupe + dispatch happen in the processor, keyed on
 * the event id so redelivery is processed at most once). (Requirements 19.3,
 * 19.4)
 */
class WebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $event = $request->attributes->get(VerifyStripeSignature::EVENT_ATTRIBUTE);

        if (! $event instanceof StripeWebhookEvent) {
            // Should not happen: the middleware always stashes a verified event
            // before this action runs. Fail closed rather than enqueue nothing.
            throw new HttpException(400, 'Missing verified webhook event.');
        }

        // Offload heavy processing to the DB queue and acknowledge Stripe fast.
        // (Requirement 19.4)
        ProcessWebhookJob::dispatch($event);

        return response()->json(['received' => true]);
    }
}
