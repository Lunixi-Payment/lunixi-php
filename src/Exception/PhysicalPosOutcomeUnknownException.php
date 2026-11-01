<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Exception;

/**
 * A physical POS payment write (start, reversal, cancel) ended without a
 * definite answer. One of its attempts:
 *   - got no HTTP response (network error, timeout, connection dropped),
 *   - got a 2xx whose body was empty, not JSON or without a `payment`,
 *   - got a 5xx other than the typed "nothing was written" codes
 *     (`physical_pos.presence.unavailable`, `signing.unavailable`,
 *     `service.busy`, `service.clock_skew`, `service.not_configured`).
 * Once an attempt was uncertain, the call ends with this exception whatever
 * the last answer was (a 429 or 409 included): the payment MAY OR MAY NOT
 * exist under that key.
 *
 * Do not start a new payment with a new key: that can charge the card twice.
 * Repeat the same call with {@see getIdempotencyKey()} — if the payment was
 * written you get its current view (`replayed: true`), otherwise it is created —
 * or find it with `listPayments(['merchantReference' => …])`.
 *
 * {@see getPrevious()} is the last attempt's error (an ApiException, or the
 * transport's own Throwable); status, code and request id are copied from it
 * when it is an ApiException.
 */
class PhysicalPosOutcomeUnknownException extends ApiException
{
    private string $idempotencyKey;

    private ?int $retryAfter;

    private int $attempts;

    public function __construct(\Throwable $cause, string $idempotencyKey, int $attempts = 1)
    {
        $api = $cause instanceof ApiException ? $cause : null;
        parent::__construct(
            'The outcome of the physical POS payment request is unknown. Repeat the same call with the same Idempotency-Key, '
                . 'or look the payment up; do not start it with a new key.',
            $api !== null ? $api->getStatusCode() : 0,
            $api !== null ? $api->getErrorCode() : null,
            $api !== null ? $api->getResponseBody() : null,
            $api !== null ? $api->getRequestId() : null,
            $cause,
            $api !== null ? $api->getResponseHeaders() : null
        );
        $this->idempotencyKey = $idempotencyKey;
        $this->attempts = max(1, $attempts);
        $details = ($api !== null ? ($api->getResponseBody() ?? []) : [])['details'] ?? null;
        $seconds = is_array($details) ? ($details['retryAfterSeconds'] ?? null) : null;
        $this->retryAfter = ($api !== null ? $api->getRetryAfter() : null)
            ?? (is_int($seconds) && $seconds >= 0 ? $seconds : null);
    }

    /** The key that was sent; repeat the call with exactly this key. */
    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    /** `Retry-After` / `details.retryAfterSeconds` of the last answer, else null. */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }

    /** How many attempts the SDK made with this key. */
    public function getAttempts(): int
    {
        return $this->attempts;
    }
}
