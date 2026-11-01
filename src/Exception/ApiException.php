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

    /** @var array<string,string> Response headers (lower-cased names); empty when unknown. */
    private array $responseHeaders;

    /**
     * @param array<string,mixed>|null  $responseBody
     * @param array<string,string>|null $responseHeaders Response headers; optional (added after 0.29.6, so
     *   existing callers and subclasses that pass six arguments keep working).
     */
    public function __construct(
        string $message,
        int $statusCode = 0,
        ?string $errorCode = null,
        ?array $responseBody = null,
        ?string $requestId = null,
        ?\Throwable $previous = null,
        ?array $responseHeaders = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->statusCode = $statusCode;
        $this->errorCode = $errorCode;
        $this->responseBody = $responseBody;
        $this->requestId = $requestId;
        $headers = [];
        foreach ($responseHeaders ?? [] as $name => $value) {
            if (is_string($name) && is_scalar($value)) {
                $headers[strtolower($name)] = (string) $value;
            }
        }
        $this->responseHeaders = $headers;
    }

    /** @return array<string,string> Response headers with lower-cased names (empty when not known). */
    public function getResponseHeaders(): array
    {
        return $this->responseHeaders;
    }

    public function getResponseHeader(string $name): ?string
    {
        return $this->responseHeaders[strtolower($name)] ?? null;
    }

    /**
     * The `Retry-After` header in seconds (a delay, or an HTTP date turned into
     * the seconds left), or null when the response had none.
     */
    public function getRetryAfter(): ?int
    {
        $value = $this->getResponseHeader('retry-after');
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if (preg_match('/^[0-9]{1,9}$/', $value) === 1) {
            return (int) $value;
        }
        $time = strtotime($value);

        return $time === false ? null : max(0, $time - time());
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
