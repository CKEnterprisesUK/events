<?php

namespace App\Jobs;

use App\Http\Controllers\WebhookController;
use App\Services\Stripe\StripeWebhookEvent;
use App\Services\WebhookProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs the heavy work for a verified Stripe webhook off the DB queue, so the
 * {@see WebhookController} can acknowledge Stripe quickly
 * with a 2xx and let processing happen asynchronously. (Requirement 19.4)
 *
 * The already-verified {@see StripeWebhookEvent} is carried on the job (a plain
 * serialisable value object), so no re-verification is needed when the job
 * runs. Handling is delegated to {@see WebhookProcessor}, which is idempotent
 * on the Stripe event id — so a retry after a transient failure, or a duplicate
 * enqueue from a redelivered webhook, processes the event at most once. A
 * handler exception lets the job retry; persistent failures land in
 * `failed_jobs` for inspection. (Requirements 12.6, 12.7, 19.3, 15.4)
 */
class ProcessWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(private readonly StripeWebhookEvent $event) {}

    public function handle(WebhookProcessor $processor): void
    {
        $processor->process($this->event);
    }
}
