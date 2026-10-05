<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Http;

/**
 * A completed HTTP response. Transport-agnostic value object returned by every
 * HttpClientInterface implementation (curl, WordPress HTTP API, Guzzle, …).
 */
final class HttpResponse
{
    private int $statusCode;
    /** @var array<string,string> */
    private array $headers;
    private string $body;

    /** @param array<string,string> $headers */
    public function __construct(int $statusCode, array $headers, string $body)
    {
        $this->statusCode = $statusCode;
        $this->headers = $headers;
        $this->body = $body;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function isSuccess(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * Decodes the JSON body to an associative array (empty array for an empty body).
     *
     * @return array<string,mixed>
     */
    public function json(): array
    {
        if (trim($this->body) === '') {
            return [];
        }
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : [];
    }
}
