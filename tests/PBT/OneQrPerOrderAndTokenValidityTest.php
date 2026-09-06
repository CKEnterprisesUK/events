<?php

namespace Tests\PBT;

use App\Models\Event;
use App\Models\Order;
use App\Services\QrService;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Property-based test for one QR per Order and token validity (Property 23).
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy. The QR_Token is a pure
 * function of the Order_Reference and the stable Platform secret
 * (`config('qr.hmac_secret')`, fixed in phpunit.xml), so the property holds
 * without any table DDL — Orders are created through the Order factory and
 * RefreshDatabase rolls each iteration's rows back (no Schema/TRUNCATE here).
 *
 * The invariants, per Requirements 14.1, 14.2, 16.4, 16.5:
 * - Each Order has exactly ONE valid QR_Token: `HMAC-SHA256(secret,
 *   Order_Reference)`. The token is deterministic — the same Order_Reference
 *   always yields the same token — and re-derivable from the reference alone.
 * - Distinct Order_References yield distinct tokens.
 * - {@see QrService::verify()} / {@see QrService::verifyPayload()} accept
 *   exactly that correct token and reject any tampered token, a token minted
 *   under a different secret, or a token belonging to a different Order.
 */
class OneQrPerOrderAndTokenValidityTest extends PbtTestCase
{
    use RefreshDatabase;

    /** Character pool for a generated Order_Reference: uppercase alphanumerics. */
    private const REFERENCE_CHARS = [
        'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M',
        'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z',
        '0', '1', '2', '3', '4', '5', '6', '7', '8', '9',
    ];

    /**
     * Property 23: One QR per order and token validity — each Order has exactly
     * one valid QR_Token, `HMAC(secret, Order_Reference)`. The token is
     * deterministic (same reference -> same token), distinct references yield
     * distinct tokens, verify accepts the correct token, and verify rejects
     * tampered, wrong-secret, and foreign tokens.
     *
     * **Validates: Requirements 14.1, 14.2, 16.4, 16.5**
     */
    // Feature: event-ticketing-platform, Property 23: One QR per order and token validity — exactly one QR encoding HMAC(secret, Order_Reference); verify succeeds for that token, fails for tampered/foreign tokens
    public function test_each_order_has_one_valid_qr_token_that_verifies_only_when_correct(): void
    {
        $qr = app(QrService::class);

        $this->forAll(
            // Two distinct Order_References per iteration (12 uppercase
            // alphanumerics, the checkout path's shape). Two references let us
            // exercise both "distinct references -> distinct tokens" and the
            // "foreign token" rejection against a different Order.
            $this->referenceGenerator(),
            $this->referenceGenerator(),
        )
            ->then(function (string $refA, string $refB) use ($qr): void {
                // Keep the two references distinct so cross-Order checks are real.
                if ($refA === $refB) {
                    $refB = ($refB[0] === 'A' ? 'B' : 'A').substr($refB, 1);
                }

                // Two Orders carrying those references, created through the same
                // factory the checkout path uses. Each Order carries exactly one
                // QR payload derived from its reference. (Requirement 14.1)
                $event = Event::factory()->create();
                $orderA = Order::factory()->forEvent($event)->create(['order_reference' => $refA]);
                $orderB = Order::factory()->forEvent($event)->create(['order_reference' => $refB]);

                // The one valid token for each Order is HMAC(secret, reference).
                // (Requirement 14.2)
                $tokenA = $qr->token($refA);
                $tokenB = $qr->token($refB);

                // Determinism: the same Order_Reference always yields the same
                // token, whether taken from the reference or the Order. Nothing
                // is stored — it is re-derived each call. (Requirement 14.2)
                $this->assertSame(
                    $tokenA,
                    $qr->token($refA),
                    'The QR_Token must be deterministic for a given Order_Reference.'
                );
                $this->assertSame(
                    $refA.'.'.$tokenA,
                    $qr->payloadFor($orderA->fresh()),
                    "An Order's QR payload must be {order_reference}.{token} for its own reference."
                );

                // Distinct Order_References yield distinct tokens.
                $this->assertNotSame(
                    $tokenA,
                    $tokenB,
                    'Distinct Order_References must yield distinct QR_Tokens.'
                );

                // verify accepts exactly the correct token for each Order, and
                // verifyPayload recovers the reference from the correct payload.
                // (Requirements 16.4, 16.5)
                $this->assertTrue(
                    $qr->verify($refA, $tokenA),
                    'verify must accept the correct token for its Order_Reference.'
                );
                $this->assertSame(
                    $refA,
                    $qr->verifyPayload($qr->payloadFor($orderA->fresh())),
                    'verifyPayload must recover the Order_Reference from the correct payload.'
                );

                // Foreign token: Order B's token must not verify for Order A.
                // (Requirement 16.5)
                $this->assertFalse(
                    $qr->verify($refA, $tokenB),
                    "Another Order's token must not verify for this Order_Reference."
                );

                // Tampered token: flipping a hex nibble must fail verification.
                // (Requirement 16.4)
                $tampered = $this->tamper($tokenA);
                $this->assertNotSame($tokenA, $tampered);
                $this->assertFalse(
                    $qr->verify($refA, $tampered),
                    'A tampered token must fail verification.'
                );

                // Wrong-secret token: an HMAC of the same reference under a
                // different secret must fail — verification is bound to the
                // Platform secret. (Requirements 14.2, 16.4)
                $wrongSecretToken = hash_hmac('sha256', $refA, config('qr.hmac_secret').'-not-the-real-secret');
                $this->assertNotSame($tokenA, $wrongSecretToken);
                $this->assertFalse(
                    $qr->verify($refA, $wrongSecretToken),
                    'A token minted under a different secret must fail verification.'
                );

                // The tampered/foreign/wrong-secret payloads are likewise
                // rejected end-to-end by verifyPayload.
                $this->assertNull(
                    $qr->verifyPayload($refA.'.'.$tampered),
                    'verifyPayload must reject a payload with a tampered token.'
                );
                $this->assertNull(
                    $qr->verifyPayload($refA.'.'.$tokenB),
                    'verifyPayload must reject a payload carrying a foreign token.'
                );
            });
    }

    /**
     * An Eris generator for a 12-character uppercase-alphanumeric
     * Order_Reference (the checkout path's shape).
     */
    private function referenceGenerator(): Generator
    {
        return Generator\map(
            static fn (array $chars): string => implode('', $chars),
            Generator\vector(12, Generator\elements(...self::REFERENCE_CHARS)),
        );
    }

    /**
     * Flip a single hex character of a token to produce a tampered token of the
     * same length. Deterministic: swaps the first nibble to a guaranteed
     * different value.
     */
    private function tamper(string $token): string
    {
        $first = $token[0];
        $replacement = $first === '0' ? '1' : '0';

        return $replacement.substr($token, 1);
    }
}
