<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Webhook;

use Lunixi\Sdk\Exception\WebhookVerificationException;

/**
 * Verifies inbound Lunixi webhooks.
 *
 * The `x-lunixi-signature` header is a comma-separated list of `scheme=value`
 * items. The only signature scheme is v2; the list form exists solely for the
 * 24h secret-rotation grace window, during which the gateway sends two items
 * (`v2=<newSecretHmac>,v2=<oldSecretHmac>`).
 *
 *   v2 = hex( HMAC_SHA256(secret, "<eventId>.<timestamp>.<rawBody>") )
 *
 * where rawBody is the EXACT raw request body bytes (UTF-8) — never parsed or
 * re-serialized for signing — and timestamp is the exact string of the
 * `x-lunixi-signature-timestamp` header (ISO 8601).
 *
 * Verification: split the header on ',', trim, take the `v2=` items and
 * compare each against the computed HMAC with hash_equals — valid if ANY
 * matches. A header without a v2 item ALWAYS fails (unknown schemes are
 * ignored).
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

        $v2Items = [];
        foreach (self::parseSignatureHeader($signatureHeader) as $item) {
            if ($item['scheme'] === 'v2') {
                $v2Items[] = $item;
            }
        }
        if ($v2Items === []) {
            throw new WebhookVerificationException('Webhook signature has no v2 item.');
        }

        // HMAC over the EXACT raw body bytes — no parse, no re-serialization.
        $expected = hash_hmac('sha256', $eventId . '.' . $timestamp . '.' . $rawBody, $secret);
        $anyMatch = false;
        foreach ($v2Items as $item) {
            if (hash_equals($expected, $item['value'])) {
                $anyMatch = true;
            }
        }
        if (!$anyMatch) {
            throw new WebhookVerificationException('Webhook signature mismatch.');
        }

        $decoded = json_decode($rawBody, true);
        $envelope = is_array($decoded) ? $decoded : [];

        // The Lunixi delivery envelope carries the AUTHENTICATED event type + id in
        // the SIGNED body; the x-lunixi-event-type header is NOT part of the
        // signature. Prefer the body, fall back to the header (flat bodies).
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
     * Splits "v2=abc,v2=def" into [['scheme'=>'v2','value'=>'abc'], …].
     * Unknown schemes are carried through (and ignored by the caller) so new
     * schemes can be introduced without breaking old verifiers.
     *
     * @return array<int,array{scheme:string,value:string}>
     */
    private static function parseSignatureHeader(string $header): array
    {
        $items = [];
        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $eq = strpos($part, '=');
            if ($eq === false || $eq === 0) {
                $items[] = ['scheme' => '', 'value' => $part];
                continue;
            }
            $items[] = [
                'scheme' => trim(substr($part, 0, $eq)),
                'value' => trim(substr($part, $eq + 1)),
            ];
        }
        return $items;
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
