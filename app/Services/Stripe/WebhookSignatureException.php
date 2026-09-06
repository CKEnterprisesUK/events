<?php

namespace App\Services\Stripe;

use App\Http\Middleware\VerifyStripeSignature;
use RuntimeException;

/**
 * Thrown by {@see StripePaymentService::constructWebhookEvent()} when an
 * incoming webhook cannot be verified — the signature is absent, malformed, or
 * does not match the payload under the webhook signing secret. The
 * {@see VerifyStripeSignature} middleware catches this and
 * rejects the request with an error response before any processing or state
 * change occurs. (Requirements 19.1, 19.2)
 */
class WebhookSignatureException extends RuntimeException {}
