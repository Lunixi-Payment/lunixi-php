<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Exception;

/**
 * HTTP 429 `RATE_LIMITED` from a physical POS route. Payment writes (start,
 * reversal, cancel) and management writes share a per-key, per-minute budget;
 * reads are not limited.
 *
 * {@see getRetryAfter()} is the wait in seconds: the HTTP `Retry-After`
 * header, or `details.retryAfterSeconds` of the error body when the header is
 * missing; null when the gateway sent neither. Retrying a payment write after
 * the wait is safe with the SAME Idempotency-Key. A 429 that follows an
 * attempt with an unknown outcome is reported as
 * PhysicalPosOutcomeUnknownException instead.
 */
class PhysicalPosRateLimitException extends ApiException
{
    private ?int $retryAfter;

    /**
     * @param array<string,mixed>|null  $responseBody
     * @param array<string,string>|null $responseHeaders
     */
    public function __construct(
        string $message,
        ?string $errorCode = null,
        ?array $responseBody = null,
        ?string $requestId = null,
        ?int $retryAfter = null,
        ?\Throwable $previous = null,
        ?array $responseHeaders = null
    ) {
        parent::__construct($message, 429, $errorCode, $responseBody, $requestId, $previous, $responseHeaders);
        $this->retryAfter = $retryAfter ?? parent::getRetryAfter() ?? self::detailsRetryAfter($responseBody);
    }

    /** Seconds to wait before the next write: `Retry-After`, else `details.retryAfterSeconds`, else null. */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }

    /** @param array<string,mixed>|null $body */
    private static function detailsRetryAfter(?array $body): ?int
    {
        $details = ($body ?? [])['details'] ?? null;
        $seconds = is_array($details) ? ($details['retryAfterSeconds'] ?? null) : null;

        return is_int($seconds) && $seconds >= 0 ? $seconds : null;
    }
}
