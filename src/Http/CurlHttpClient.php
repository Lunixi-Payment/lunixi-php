<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Http;

use Lunixi\Sdk\Exception\ApiException;

/**
 * Default cURL transport. Zero third-party dependencies (only ext-curl), so it
 * scopes cleanly when bundled. TLS verification is always on; redirects are NOT
 * followed (a payment API must never silently follow a redirect).
 */
final class CurlHttpClient implements HttpClientInterface
{
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): HttpResponse
    {
        $ch = curl_init();
        if ($ch === false) {
            throw new ApiException('Could not initialise cURL.');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $responseHeaders = [];
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => (int) ceil($timeout),
            CURLOPT_TIMEOUT_MS => (int) ($timeout * 1000),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ];

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($ch, $options);

        $rawBody = curl_exec($ch);
        if ($rawBody === false) {
            $error = curl_error($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            throw new ApiException("HTTP transport error ({$errno}): {$error}");
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return new HttpResponse($statusCode, $responseHeaders, (string) $rawBody);
    }
}
