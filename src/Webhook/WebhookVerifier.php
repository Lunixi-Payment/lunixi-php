<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Webhook;

use Lunixi\Sdk\Exception\WebhookVerificationException;

/**
 * Verifies inbound Lunixi webhooks, byte-for-byte matching the gateway's
 * WebhookSignatureService:
 *
 *   payloadHash = sha256_hex( stableStringify(body) )
 *   signature   = hex( HMAC_SHA256(secret, "<eventId>.<timestamp>.<payloadHash>") )
 *   header      = "x-lunixi-signature: sha256=<signature>"
 *
 * where stableStringify === JSON.stringify(sortObject(value)): object keys sorted
 * recursively, array order preserved. The delivered body IS the signed payload
 * (no envelope); eventId/type/timestamp travel in headers. Because the gateway
 * signs the SORTED re-serialization (not the raw bytes), we must parse the body
 * and re-serialize it the same way — HMAC'ing the raw body would not match.
 *
 * FAIL-CLOSED: any discrepancy throws; never act on an unverified payload.
 */
final class WebhookVerifier
{
    public const HEADER_SIGNATURE = 'x-lunixi-signature';
    public const HEADER_TIMESTAMP = 'x-lunixi-signature-timestamp';
    public const HEADER_EVENT_ID = 'x-lunixi-event-id';
    public const HEADER_EVENT_TYPE = 'x-lunixi-event-type';

    /** Default replay window (seconds) for the signature timestamp. */
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    /**
     * Verifies a received webhook and returns the authenticated event.
     *
     * @param string                $rawBody   The exact raw request body bytes.
     * @param array<string,string>  $headers   Request headers (any case).
     * @param string                $secret    The endpoint's webhook secret.
     * @param int|null              $tolerance Max timestamp skew in seconds (null → no check; default 300).
     * @throws WebhookVerificationException FAIL-CLOSED on any verification failure.
     */
    public function verify(
        string $rawBody,
        array $headers,
        string $secret,
        ?int $tolerance = self::DEFAULT_TOLERANCE_SECONDS
    ): WebhookEvent {
        if ($secret === '') {
            throw new WebhookVerificationException('Webhook secret is not configured.');
        }

        $normalized = self::normalizeHeaders($headers);
        $signatureHeader = $normalized[self::HEADER_SIGNATURE] ?? '';
        $timestamp = $normalized[self::HEADER_TIMESTAMP] ?? '';
        $eventId = $normalized[self::HEADER_EVENT_ID] ?? '';
        $eventType = $normalized[self::HEADER_EVENT_TYPE] ?? '';

        if ($signatureHeader === '' || $timestamp === '' || $eventId === '') {
            throw new WebhookVerificationException('Missing webhook signature, timestamp or event id header.');
        }

        if ($tolerance !== null) {
            self::assertFreshTimestamp($timestamp, $tolerance);
        }

        $payloadHash = self::payloadHash($rawBody);
        $expected = 'sha256=' . hash_hmac('sha256', $eventId . '.' . $timestamp . '.' . $payloadHash, $secret);

        if (!hash_equals($expected, $signatureHeader)) {
            throw new WebhookVerificationException('Webhook signature mismatch.');
        }

        $decoded = json_decode($rawBody, true);
        $envelope = is_array($decoded) ? $decoded : [];

        // The Lunixi delivery envelope carries the AUTHENTICATED event type + id in
        // the SIGNED body; the x-lunixi-event-type header is NOT part of the
        // signature. Prefer the body, fall back to the header (flat/legacy bodies).
        $type = (isset($envelope['type']) && is_string($envelope['type']) && $envelope['type'] !== '')
            ? $envelope['type']
            : $eventType;
        $id = (isset($envelope['id']) && is_string($envelope['id']) && $envelope['id'] !== '')
            ? $envelope['id']
            : $eventId;
        $data = (isset($envelope['data']) && is_array($envelope['data'])) ? $envelope['data'] : $envelope;

        return new WebhookEvent($id, $type, $timestamp, $data, $envelope);
    }

    /**
     * The stable canonical form of a JSON body — JSON.stringify(sortObject(value)),
     * matching the gateway exactly. Public for testing against known gateway output.
     */
    public static function stableStringify(string $rawBody): string
    {
        $decoded = json_decode($rawBody); // objects → stdClass, arrays → list (disambiguates {} vs [])
        if ($decoded === null && trim($rawBody) !== 'null') {
            throw new WebhookVerificationException('Webhook body is not valid JSON.');
        }

        $encoded = json_encode(
            self::sortValue($decoded),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($encoded === false) {
            throw new WebhookVerificationException('Could not re-serialize webhook body.');
        }

        return $encoded;
    }

    /** sha256 hex of the stable-stringified body (gateway hashPayload equivalent). */
    public static function payloadHash(string $rawBody): string
    {
        return hash('sha256', self::stableStringify($rawBody));
    }

    /**
     * Recursively sorts object keys (lexicographic, like JS Array.sort on Object.keys),
     * preserving array order — mirrors the gateway's sortObject.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function sortValue($value)
    {
        if (is_array($value)) { // JSON array → preserve order
            return array_map([self::class, 'sortValue'], $value);
        }
        if ($value instanceof \stdClass) { // JSON object → sort keys
            $vars = get_object_vars($value);
            ksort($vars, SORT_STRING);
            $sorted = new \stdClass();
            foreach ($vars as $key => $val) {
                $sorted->{$key} = self::sortValue($val);
            }
            return $sorted;
        }
        return $value;
    }

    /** @param array<string,string> $headers @return array<string,string> lower-cased keys */
    private static function normalizeHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $key => $value) {
            $out[strtolower((string) $key)] = is_array($value) ? (string) reset($value) : (string) $value;
        }
        return $out;
    }

    private static function assertFreshTimestamp(string $timestamp, int $tolerance): void
    {
        $ts = strtotime($timestamp);
        if ($ts === false) {
            throw new WebhookVerificationException('Webhook timestamp is not a valid date.');
        }
        if (abs(time() - $ts) > $tolerance) {
            throw new WebhookVerificationException('Webhook timestamp is outside the allowed tolerance (replay protection).');
        }
    }
}
