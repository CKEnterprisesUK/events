<?php

namespace App\Services;

use App\Models\Order;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelMedium;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use InvalidArgumentException;
use RuntimeException;

/**
 * Produces and verifies the single QR_Code carried on an Order's ticket email
 * and presented at entry. (Requirements 14.1, 14.2, 16.3, 16.4, 16.5)
 *
 * ## What the QR encodes
 *
 * Exactly one QR is generated per Order (Requirement 14.1). Its QR_Token is an
 * HMAC-SHA256 of the Order_Reference computed with the Platform-wide secret
 * ({@see config('qr.hmac_secret')}) (Requirement 14.2). The scannable payload
 * carried in the QR image is the Order_Reference and its token joined by a
 * single `.` separator:
 *
 *     {order_reference}.{qr_token}
 *
 * This lets the scanner recover the Order_Reference, recompute the HMAC with
 * the same secret, and reject any payload whose token does not match — a
 * tampered token, or a token minted with a different secret, verifies false
 * (Requirements 16.4, 16.5). Because the token is a pure function of the
 * Order_Reference and a stable secret, it is deterministic and re-derivable:
 * nothing is stored, and a re-issued email for the same Order carries the same
 * QR.
 *
 * ## Determinism / stability
 *
 * The HMAC secret must stay constant across deploys so QR tokens issued in the
 * past continue to verify (design → Property 23). This service reads the secret
 * once per call from config; it never persists tokens.
 */
class QrService
{
    /**
     * Separator between the Order_Reference and its token inside the scannable
     * payload. `.` never appears in an Order_Reference (uppercase [A-Z0-9]), so
     * the payload splits unambiguously back into (reference, token).
     */
    private const SEPARATOR = '.';

    /**
     * Compute the QR_Token for an Order_Reference: HMAC-SHA256 of the reference
     * under the Platform secret, hex-encoded. Deterministic for a given
     * (reference, secret) pair. (Requirement 14.2)
     */
    public function token(string $orderReference): string
    {
        if ($orderReference === '') {
            throw new InvalidArgumentException('Order reference must not be empty.');
        }

        return hash_hmac('sha256', $orderReference, $this->secret());
    }

    /**
     * The scannable payload embedded in the Order's QR_Code: the Order_Reference
     * and its token joined by `.`. This is the exact string the scanner decodes
     * and passes back to {@see verify()}. (Requirements 14.1, 14.2)
     */
    public function payloadFor(Order $order): string
    {
        return $this->payload($order->order_reference);
    }

    /**
     * The scannable payload for a raw Order_Reference. (Requirements 14.1, 14.2)
     */
    public function payload(string $orderReference): string
    {
        return $orderReference.self::SEPARATOR.$this->token($orderReference);
    }

    /**
     * Render the scannable payload for an Order as a PNG QR image and return the
     * raw image bytes. This is the single QR_Code carried on the ticket email;
     * it encodes the exact string {@see payloadFor()} produces, so scanning the
     * image yields the same `reference.token` the scanner decodes and verifies.
     * (Requirements 14.1, 14.2)
     */
    public function pngFor(Order $order, int $size = 300): string
    {
        return $this->png($this->payloadFor($order), $size);
    }

    /**
     * Render an arbitrary scannable payload as a PNG QR image, returning the raw
     * bytes. Uses medium error correction so the code stays readable if the
     * printed/screen render is slightly degraded. Requires the GD extension.
     */
    public function png(string $payload, int $size = 300): string
    {
        if ($payload === '') {
            throw new InvalidArgumentException('QR payload must not be empty.');
        }

        $qrCode = new QrCode(
            data: $payload,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: new ErrorCorrectionLevelMedium(),
            size: $size,
            margin: 10,
        );

        return (new PngWriter())->write($qrCode)->getString();
    }

    /**
     * Split a scanned payload into its (Order_Reference, token) parts, or null
     * when the payload is not the expected `reference.token` shape. Used by the
     * scanner to reject an undecodable code before any HMAC work.
     * (Requirement 16.3)
     *
     * @return array{0: string, 1: string}|null
     */
    public function decode(string $payload): ?array
    {
        $pos = strpos($payload, self::SEPARATOR);

        if ($pos === false) {
            return null;
        }

        $reference = substr($payload, 0, $pos);
        $token = substr($payload, $pos + 1);

        if ($reference === '' || $token === '') {
            return null;
        }

        return [$reference, $token];
    }

    /**
     * Whether a token is the valid HMAC for the given Order_Reference. Uses a
     * constant-time comparison so a mismatch cannot be probed by timing. A
     * tampered token, or one minted for another reference or under a different
     * secret, returns false. (Requirements 16.4, 16.5)
     */
    public function verify(string $orderReference, string $token): bool
    {
        if ($orderReference === '' || $token === '') {
            return false;
        }

        return hash_equals($this->token($orderReference), $token);
    }

    /**
     * Verify a whole scanned payload (`reference.token`): decode it, then check
     * the token against the recomputed HMAC. Returns the Order_Reference on a
     * valid payload, or null when the payload is undecodable or the token is
     * invalid. (Requirements 16.3, 16.4, 16.5)
     */
    public function verifyPayload(string $payload): ?string
    {
        $decoded = $this->decode($payload);

        if ($decoded === null) {
            return null;
        }

        [$reference, $token] = $decoded;

        return $this->verify($reference, $token) ? $reference : null;
    }

    /**
     * The Platform HMAC secret. Must be configured (falls back to APP_KEY in
     * config) and stable across deploys so past tokens keep verifying.
     */
    private function secret(): string
    {
        $secret = config('qr.hmac_secret');

        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('QR HMAC secret is not configured (qr.hmac_secret).');
        }

        return $secret;
    }
}
