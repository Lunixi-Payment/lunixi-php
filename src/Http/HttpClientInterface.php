<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Http;

use Lunixi\Sdk\Exception\ApiException;

/**
 * Transport abstraction. The SDK ships a curl implementation; integrations may
 * inject their own (e.g. the WordPress plugin wraps `wp_remote_request`, so the
 * SDK respects the site's proxy/SSL/timeout settings and SSRF posture).
 *
 * Implementations return an HttpResponse for ANY completed exchange (including
 * 4xx/5xx — the caller decides) and throw {@see ApiException} (statusCode 0)
 * ONLY on a transport failure (DNS, connect, TLS, timeout).
 */
interface HttpClientInterface
{
    /**
     * @param string                $method  HTTP method (upper-case).
     * @param string                $url     Absolute URL.
     * @param array<string,string>  $headers Request headers.
     * @param string|null           $body    Raw request body (null for none).
     * @param float                 $timeout Timeout in seconds.
     * @throws ApiException on transport failure.
     */
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): HttpResponse;
}
