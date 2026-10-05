<?php

declare(strict_types=1);

namespace Lunixi\Sdk;

use Lunixi\Sdk\Auth\CanonicalRequest;
use Lunixi\Sdk\Auth\Ed25519Signer;
use Lunixi\Sdk\Auth\TokenManager;
use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Http\HttpClientInterface;
use Lunixi\Sdk\Http\HttpResponse;

/**
 * Executes authenticated gateway requests: attaches the bearer token, adds the
 * Ed25519 step-up signature for money-mutating calls, sets the Idempotency-Key,
 * and applies a money-SAFE retry policy.
 *
 * Retry policy (never risks a double-submit):
 *   - 401            → invalidate token, re-auth, retry once (auth was rejected → no mutation occurred).
 *   - network / 5xx  → retry ONLY when the call is idempotent (GET/HEAD, or an Idempotency-Key is set).
 *   - 4xx            → never retried; thrown as ApiException with the gateway error code.
 */
final class ApiClient
{
    /**
     * Encoding of a JSON request body. It must produce the same bytes as the
     * gateway's `JSON.stringify` of the parsed body, because SignatureGuard
     * recomputes the `Digest` that way. `JSON_UNESCAPED_LINE_TERMINATORS`
     * matters: without it PHP escapes U+2028/U+2029 while `JSON.stringify` does
     * not, and any text containing them fails with `INVALID_DIGEST`. Key order
     * is the other half of that contract: see {@see jsonOrdered()}.
     */
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS;

    /** Largest JavaScript array index (2^32 - 2); such keys are ordered numerically by `JSON.parse`. */
    private const MAX_ARRAY_INDEX = 4294967294;

    private const ACCEPT_JSON = 'application/json';
    private const ACCEPT_BINARY = 'image/svg+xml, image/png, application/json';

    private Configuration $config;
    private Ed25519Signer $signer;
    private TokenManager $tokens;
    private HttpClientInterface $http;

    public function __construct(
        Configuration $config,
        Ed25519Signer $signer,
        TokenManager $tokens,
        HttpClientInterface $http
    ) {
        $this->config = $config;
        $this->signer = $signer;
        $this->tokens = $tokens;
        $this->http = $http;
    }

    /**
     * @param string                    $method  HTTP method.
     * @param string                    $path    Path beginning with "/" (signed verbatim as the canonical URL).
     * @param array<string,mixed>|null  $body    JSON body for the request, or null.
     * @param array{stepUp?:bool, idempotencyKey?:string|null, query?:array<string,scalar>} $options
     * @return array<string,mixed> Decoded JSON response.
     * @throws ApiException
     */
    public function request(string $method, string $path, ?array $body = null, array $options = []): array
    {
        return $this->send($method, $path, $body, $options, self::ACCEPT_JSON)->json();
    }

    /**
     * Same as {@see request()} for an endpoint that answers with bytes (an
     * image) instead of JSON. Returns the successful response untouched; an
     * error response is still read as a JSON error and thrown.
     *
     * @param array{stepUp?:bool, idempotencyKey?:string|null, query?:array<string,scalar>} $options
     * @throws ApiException
     */
    public function requestBinary(string $method, string $path, array $options = []): HttpResponse
    {
        return $this->send($method, $path, null, $options, self::ACCEPT_BINARY);
    }

    /**
     * @param array<string,mixed>|null $body
     * @param array{stepUp?:bool, idempotencyKey?:string|null, query?:array<string,scalar>} $options
     * @throws ApiException
     */
    private function send(string $method, string $path, ?array $body, array $options, string $accept): HttpResponse
    {
        $method = strtoupper($method);
        $stepUp = (bool) ($options['stepUp'] ?? false);
        $idempotencyKey = $options['idempotencyKey'] ?? null;

        $signedPath = $path;
        if (!empty($options['query'])) {
            $signedPath .= '?' . http_build_query($options['query']);
        }
        $url = $this->config->baseUrl() . $signedPath;

        $rawBody = $body === null ? null : (string) json_encode(self::jsonOrdered($body), self::JSON_FLAGS);

        $idempotent = in_array($method, ['GET', 'HEAD'], true) || (is_string($idempotencyKey) && $idempotencyKey !== '');

        $maxAttempts = $this->config->maxRetries() + 1;
        $reAuthed = false;

        for ($attempt = 1; ; $attempt++) {
            $headers = $this->buildHeaders($method, $signedPath, $rawBody, $stepUp, $idempotencyKey, $accept);

            try {
                $response = $this->http->send($method, $url, $headers, $rawBody, $this->config->timeout());
            } catch (ApiException $transportError) {
                if ($idempotent && $attempt < $maxAttempts) {
                    self::backoff($attempt);
                    continue;
                }
                throw $transportError;
            }

            if ($response->isSuccess()) {
                return $response;
            }

            // 401 → token likely expired/rotated; re-auth once (no mutation happened).
            if ($response->statusCode() === 401 && !$reAuthed) {
                $this->tokens->invalidate();
                $reAuthed = true;
                continue;
            }

            // Retryable server/transport errors, only when safe.
            if ($response->statusCode() >= 500 && $idempotent && $attempt < $maxAttempts) {
                self::backoff($attempt);
                continue;
            }

            throw $this->toApiException($response);
        }
    }

    /**
     * @return array<string,string>
     */
    private function buildHeaders(
        string $method,
        string $signedPath,
        ?string $rawBody,
        bool $stepUp,
        ?string $idempotencyKey,
        string $accept
    ): array {
        $headers = [
            'Accept' => $accept,
            'User-Agent' => $this->config->userAgent(),
            'Authorization' => 'Bearer ' . $this->tokens->getToken(),
        ];

        if ($rawBody !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        if (is_string($idempotencyKey) && $idempotencyKey !== '') {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        if ($stepUp) {
            $date = gmdate('Y-m-d\TH:i:s\Z');
            $nonce = bin2hex(random_bytes(16));
            $digest = $rawBody !== null ? CanonicalRequest::digestForBody($rawBody) : null;
            $canonical = CanonicalRequest::build($method, $signedPath, $date, $nonce, $digest);

            $headers[CanonicalRequest::HEADER_KEY_ID] = $this->config->keyId();
            $headers[CanonicalRequest::HEADER_DATE] = $date;
            $headers[CanonicalRequest::HEADER_NONCE] = $nonce;
            $headers[CanonicalRequest::HEADER_SIGNATURE] = $this->signer->sign($canonical);
            if ($digest !== null) {
                $headers[CanonicalRequest::HEADER_DIGEST] = $digest;
            }
        }

        return $headers;
    }

    private function toApiException(HttpResponse $response): ApiException
    {
        $body = $response->json();
        $code = isset($body['code']) && is_string($body['code']) ? $body['code'] : null;
        $message = isset($body['message']) && is_string($body['message'])
            ? $body['message']
            : 'Gateway request failed (HTTP ' . $response->statusCode() . ').';
        $requestId = $response->header('x-request-id');

        return new ApiException($message, $response->statusCode(), $code, $body, $requestId);
    }

    /**
     * Orders every JSON object of a request body the way the gateway's
     * `JSON.stringify` emits it after `JSON.parse`: keys that are JavaScript
     * array indices (the canonical decimal form of an integer 0 … 2^32 - 2,
     * e.g. "0", "7", "2024") first, in ascending numeric order, then every other
     * key in insertion order. PHP keeps insertion order, so a body such as
     * `['metadata' => ['crmId' => 'A', '2024' => 'B']]` would otherwise be
     * hashed as `{"crmId":…,"2024":…}` here and as `{"2024":…,"crmId":…}` by
     * the gateway, and the signed request would fail with `INVALID_DIGEST`.
     *
     * Lists stay lists. An object stays an object (stdClass) even when the new
     * order makes its keys 0 … n-1, which PHP would otherwise encode as a list.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function jsonOrdered($value)
    {
        if ($value instanceof \JsonSerializable) {
            return self::jsonOrdered($value->jsonSerialize());
        }
        if ($value instanceof \stdClass) {
            return self::orderedObject(get_object_vars($value));
        }
        if (!is_array($value)) {
            return $value;
        }
        if (self::isList($value)) {
            return array_map(static fn ($item) => self::jsonOrdered($item), $value);
        }

        return self::orderedObject($value);
    }

    /**
     * @param array<int|string,mixed> $members
     */
    private static function orderedObject(array $members): \stdClass
    {
        $indices = [];
        $names = [];
        foreach ($members as $key => $member) {
            if (self::isArrayIndex($key)) {
                $indices[(int) $key] = $member;
            } else {
                $names[] = [(string) $key, $member];
            }
        }
        ksort($indices, SORT_NUMERIC);

        // Built as an array and cast: the cast keeps the order and, unlike
        // `$object->{$key}`, accepts every JSON key (the empty string included).
        $ordered = [];
        foreach ($indices as $key => $member) {
            $ordered[$key] = self::jsonOrdered($member);
        }
        foreach ($names as [$key, $member]) {
            $ordered[$key] = self::jsonOrdered($member);
        }

        return (object) $ordered;
    }

    /** @param int|string $key */
    private static function isArrayIndex($key): bool
    {
        if (is_int($key)) {
            return $key >= 0 && $key <= self::MAX_ARRAY_INDEX;
        }

        return preg_match('/^(?:0|[1-9][0-9]{0,9})$/', $key) === 1 && (int) $key <= self::MAX_ARRAY_INDEX;
    }

    /**
     * `array_is_list()` equivalent (the built-in needs PHP 8.1; this package
     * supports 7.4).
     *
     * @param array<mixed> $value
     */
    private static function isList(array $value): bool
    {
        $expected = 0;
        foreach ($value as $key => $_) {
            if ($key !== $expected++) {
                return false;
            }
        }

        return true;
    }

    private static function backoff(int $attempt): void
    {
        // 100ms, 200ms, 400ms … (capped). Deterministic; no thundering-herd jitter needed at SDK scale.
        $delayMs = min(2000, 100 * (2 ** ($attempt - 1)));
        usleep($delayMs * 1000);
    }
}
