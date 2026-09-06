<?php

namespace Tests\PBT;

use App\Jobs\ProcessWebhookJob;
use App\Services\Stripe\FakeStripePaymentService;
use App\Services\Stripe\StripePaymentService;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;

/**
 * Property-based test for Stripe webhook signature gating (design Property 20).
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy. Runs against the real MySQL
 * test database via {@see PbtTestCase} + RefreshDatabase.
 *
 * The rule under test (Requirements 19.1, 19.2): the fixed Platform endpoint
 * `POST /stripe/webhook` accepts (2xx, and heavy processing proceeds — a
 * {@see ProcessWebhookJob} is enqueued) a webhook IF AND ONLY IF the
 * `Stripe-Signature` header is the valid signature for the EXACT raw request
 * body under the configured webhook signing secret. Any tampering — a signature
 * computed for a different payload, one computed under the wrong secret, an
 * absent header, or a random garbage header — is rejected with HTTP 400 and NO
 * state change: nothing is enqueued and no `processed_webhooks` row is written.
 *
 * The {@see FakeStripePaymentService} bound in the test environment verifies
 * signatures deterministically as `HMAC-SHA256(rawPayload, webhook_secret)` and
 * exposes {@see FakeStripePaymentService::signPayload()} so a valid signed
 * request can be built without any live Stripe dependency.
 */
class WebhookSignatureGatingTest extends PbtTestCase
{
    use RefreshDatabase;

    /**
     * The configured webhook signing secret (set in phpunit.xml).
     */
    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();

        $this->secret = (string) Config::get('stripe.webhook_secret');
    }

    private function fakeStripe(): FakeStripePaymentService
    {
        /** @var FakeStripePaymentService $fake */
        $fake = app(StripePaymentService::class);

        return $fake;
    }

    /**
     * Build a raw webhook payload from generated fragments. Randomising the
     * event id, type, and inner object exercises signature gating over a broad
     * space of distinct raw bodies (each has its own valid signature).
     *
     * @return array{0: string, 1: string} [rawPayload, validSignature]
     */
    private function buildPayload(string $id, string $type, string $objectId): array
    {
        $payload = json_encode([
            'id' => $id,
            'type' => $type,
            'data' => ['object' => ['id' => $objectId]],
        ], JSON_THROW_ON_ERROR);

        return [$payload, hash_hmac('sha256', $payload, $this->secret)];
    }

    /**
     * Property 20: Webhook signature gating — for any raw payload and any
     * signature-validity scenario, the webhook endpoint accepts the request
     * (2xx + processing enqueued) if and only if the presented signature is the
     * valid signature for the exact raw body under the configured signing
     * secret; every tampering scenario is rejected with HTTP 400 and causes no
     * state change (no enqueue, no processed_webhooks row).
     *
     * **Validates: Requirements 19.1, 19.2**
     */
    // Feature: event-ticketing-platform, Property 20: Webhook signature gating — accept/process iff signature valid; invalid signatures rejected with error and no state change
    public function test_webhook_accepted_iff_signature_valid_for_exact_payload_and_secret(): void
    {
        $this->forAll(
            // A tokenful event id and a type drawn from the real handled set, so
            // each iteration signs a distinct raw body.
            Generator\suchThat(
                fn (string $s): bool => $s !== '',
                Generator\string(),
            ),
            Generator\elements(
                'checkout.session.completed',
                'charge.refunded',
                'charge.dispute.created',
                'account.updated',
            ),
            Generator\suchThat(
                fn (string $s): bool => $s !== '',
                Generator\string(),
            ),
            // Which signature scenario to present. Only 'valid' should be
            // accepted; every other scenario is a tampering that must be rejected.
            Generator\elements(
                'valid',
                'other_payload',
                'wrong_secret',
                'absent',
                'garbage',
            ),
            // A garbage/other-payload discriminator so the invalid signatures
            // vary across iterations rather than being a fixed constant.
            Generator\string(),
        )->then(function (
            string $id,
            string $type,
            string $objectId,
            string $scenario,
            string $noise,
        ): void {
            // Each iteration starts from a clean queue and empty ledger, so
            // "no state change" is asserted precisely against this request.
            Queue::fake();
            $this->assertDatabaseCount('processed_webhooks', 0);

            [$payload, $validSignature] = $this->buildPayload($id, $type, $objectId);

            $signatureHeader = match ($scenario) {
                // The correct HMAC over the exact raw body under the real secret.
                'valid' => $validSignature,
                // A valid HMAC, but for a DIFFERENT payload — tampered body.
                'other_payload' => hash_hmac('sha256', $payload.$noise.'x', $this->secret),
                // The correct algorithm over the exact body, but the WRONG secret.
                'wrong_secret' => hash_hmac('sha256', $payload, $this->secret.'-wrong-'.$noise),
                // No Stripe-Signature header at all.
                'absent' => null,
                // A random garbage header value.
                'garbage' => 'sig_'.bin2hex(substr($noise.'garbage', 0, 8)),
            };

            $shouldAccept = $scenario === 'valid';

            $server = $signatureHeader === null
                ? []
                : ['HTTP_STRIPE_SIGNATURE' => $signatureHeader];

            $response = $this->call(
                'POST',
                '/stripe/webhook',
                [],
                [],
                [],
                $server,
                $payload,
            );

            if ($shouldAccept) {
                // Valid signature: accepted (2xx) AND heavy processing proceeds.
                $response->assertOk();
                $response->assertJson(['received' => true]);
                Queue::assertPushed(ProcessWebhookJob::class);
            } else {
                // Any tampering: rejected with 400 and NO state change — nothing
                // enqueued and no idempotency-ledger row written.
                $response->assertStatus(400);
                Queue::assertNothingPushed();
                $this->assertDatabaseCount('processed_webhooks', 0);
            }
        });
    }
}
