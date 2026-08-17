<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Auth;

use Lunixi\Sdk\Configuration;
use Lunixi\Sdk\Exception\AuthenticationException;
use Lunixi\Sdk\Http\HttpClientInterface;

/**
 * Obtains and caches the gateway bearer token.
 *
 * `POST {authTokenPath}` with an Ed25519-signed, bodyless request (X-Key-Id /
 * X-Date / X-Nonce / X-Signature) → `{ access_token, expires_in, expires_at }`.
 * The token is cached (minus a safety margin) in the injected store so stateless
 * web requests don't re-sign and re-fetch on every call.
 */
final class TokenManager
{
    /** Refresh this many seconds BEFORE the token actually expires. */
    private const EXPIRY_SAFETY_MARGIN = 60;

    private Configuration $config;
    private Ed25519Signer $signer;
    private HttpClientInterface $http;
    private TokenStoreInterface $store;

    public function __construct(
        Configuration $config,
        Ed25519Signer $signer,
        HttpClientInterface $http,
        ?TokenStoreInterface $store = null
    ) {
        $this->config = $config;
        $this->signer = $signer;
        $this->http = $http;
        $this->store = $store ?? new InMemoryTokenStore();
    }

    /**
     * Returns a valid bearer access token, fetching a fresh one when needed.
     *
     * @throws AuthenticationException
     */
    public function getToken(bool $forceRefresh = false): string
    {
        $cacheKey = $this->config->tokenCacheKey();

        if (!$forceRefresh) {
            $cached = $this->store->get($cacheKey);
            if ($cached !== null && $cached !== '') {
                return $cached;
            }
        }

        $token = $this->fetchToken();
        $this->store->set($cacheKey, $token['access_token'], $token['ttl']);

        return $token['access_token'];
    }

    /** Drops the cached token so the next getToken() re-fetches (e.g. after a 401). */
    public function invalidate(): void
    {
        $this->store->delete($this->config->tokenCacheKey());
    }

    /**
     * @return array{access_token:string, ttl:int}
     * @throws AuthenticationException
     */
    private function fetchToken(): array
    {
        $path = $this->config->authTokenPath();
        $date = gmdate('Y-m-d\TH:i:s\Z');
        $nonce = self::nonce();

        $canonical = CanonicalRequest::build('POST', $path, $date, $nonce); // bodyless → no digest
        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => $this->config->userAgent(),
            CanonicalRequest::HEADER_KEY_ID => $this->config->keyId(),
            CanonicalRequest::HEADER_DATE => $date,
            CanonicalRequest::HEADER_NONCE => $nonce,
            CanonicalRequest::HEADER_SIGNATURE => $this->signer->sign($canonical),
        ];

        $response = $this->http->send('POST', $this->config->baseUrl() . $path, $headers, null, $this->config->timeout());

        if (!$response->isSuccess()) {
            $body = $response->json();
            $code = isset($body['code']) && is_string($body['code']) ? $body['code'] : null;
            throw new AuthenticationException(
                'Token request failed (HTTP ' . $response->statusCode() . ($code !== null ? ", {$code}" : '') . ').'
            );
        }

        $body = $response->json();
        // Gateway success envelope'u token alanlarını `data` altında döndürür
        // ({status, code, data:{access_token,...}}); düz gövdeyi de destekle.
        if (isset($body['data']) && is_array($body['data']) && isset($body['data']['access_token'])) {
            $body = $body['data'];
        }
        $accessToken = $body['access_token'] ?? null;
        if (!is_string($accessToken) || $accessToken === '') {
            throw new AuthenticationException('Token response did not contain an access_token.');
        }

        return ['access_token' => $accessToken, 'ttl' => self::resolveTtl($body)];
    }

    /** @param array<string,mixed> $body */
    private static function resolveTtl(array $body): int
    {
        $ttl = null;
        if (isset($body['expires_in']) && is_numeric($body['expires_in'])) {
            $ttl = (int) $body['expires_in'];
        } elseif (isset($body['expires_at']) && is_numeric($body['expires_at'])) {
            $ttl = (int) $body['expires_at'] - time();
        }

        if ($ttl === null || $ttl <= 0) {
            $ttl = 300; // conservative fallback
        }

        return max(30, $ttl - self::EXPIRY_SAFETY_MARGIN);
    }

    private static function nonce(): string
    {
        return bin2hex(random_bytes(16));
    }
}
