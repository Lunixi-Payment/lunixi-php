<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Auth;

/**
 * Default in-process token store (per SDK instance). Fine for long-running
 * workers/CLI; for stateless web requests inject a durable store instead.
 */
final class InMemoryTokenStore implements TokenStoreInterface
{
    /** @var array<string,array{value:string,expiresAt:int}> */
    private array $items = [];

    public function get(string $key): ?string
    {
        $item = $this->items[$key] ?? null;
        if ($item === null) {
            return null;
        }
        if ($item['expiresAt'] <= time()) {
            unset($this->items[$key]);
            return null;
        }
        return $item['value'];
    }

    public function set(string $key, string $value, int $ttlSeconds): void
    {
        $this->items[$key] = ['value' => $value, 'expiresAt' => time() + max(0, $ttlSeconds)];
    }

    public function delete(string $key): void
    {
        unset($this->items[$key]);
    }
}
