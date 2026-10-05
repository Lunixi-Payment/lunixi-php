<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Exception;

/**
 * The gateway returned a non-success HTTP status (or the transport failed).
 * Carries the HTTP status, the gateway error `code`, and the decoded body so
 * callers can branch on the machine-readable code (never on the message text).
 */
class ApiException extends LunixiException
{
    /** @var int HTTP status code (0 when the request never reached the server). */
    private int $statusCode;

    /** @var string|null Gateway machine-readable error code (e.g. "payment.intent.invalid_state"). */
    private ?string $errorCode;

    /** @var array<string,mixed>|null Decoded response body, when available. */
    private ?array $responseBody;

    /** @var string|null Request id echoed by the gateway, for support correlation. */
    private ?string $requestId;

    /**
     * @param array<string,mixed>|null $responseBody
     */
    public function __construct(
        string $message,
        int $statusCode = 0,
        ?string $errorCode = null,
        ?array $responseBody = null,
        ?string $requestId = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->statusCode = $statusCode;
        $this->errorCode = $errorCode;
        $this->responseBody = $responseBody;
        $this->requestId = $requestId;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /** @return array<string,mixed>|null */
    public function getResponseBody(): ?array
    {
        return $this->responseBody;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    /** A 4xx the client should not blindly retry (vs a retryable 5xx/network error). */
    public function isClientError(): bool
    {
        return $this->statusCode >= 400 && $this->statusCode < 500;
    }
}
