<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Auth;

/**
 * Persists the short-lived bearer token between requests. In stateless PHP
 * (WordPress, FPM) the SDK is re-created per HTTP request, so an in-process cache
 * is useless — without a real store every request re-signs and re-fetches a
 * token (extra round-trip + nonce churn). Integrations inject a durable store
 * (e.g. a WordPress transient).
 */
interface TokenStoreInterface
{
    /** Returns the cached value, or null if absent/expired. */
    public function get(string $key): ?string;

    /** Stores $value for at most $ttlSeconds. */
    public function set(string $key, string $value, int $ttlSeconds): void;

    public function delete(string $key): void;
}
