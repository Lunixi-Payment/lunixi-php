<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Subscription;

/**
 * A cursor-paginated list response (subscription REST surface uses keyset
 * pagination: `pageSize` + `nextPageToken`). Items are mapped to typed models
 * by the factory the calling client supplies.
 *
 * @template T
 */
final class CursorList
{
    /** @var array<int,mixed> */
    private array $items;
    private ?string $nextPageToken;
    private int $pageSize;

    /**
     * @param array<string,mixed> $response The full list envelope.
     * @param callable(array<string,mixed>):mixed $factory Maps a data row to a typed model.
     */
    public function __construct(array $response, callable $factory)
    {
        $data = isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
        $this->items = array_map(
            static fn ($row) => $factory(is_array($row) ? $row : []),
            array_values($data)
        );
        $token = $response['nextPageToken'] ?? null;
        $this->nextPageToken = (is_string($token) && $token !== '') ? $token : null;
        $this->pageSize = (int) ($response['pageSize'] ?? count($this->items));
    }

    /** @return array<int,mixed> */
    public function items(): array
    {
        return $this->items;
    }

    public function nextPageToken(): ?string
    {
        return $this->nextPageToken;
    }

    public function hasMore(): bool
    {
        return $this->nextPageToken !== null;
    }

    public function pageSize(): int
    {
        return $this->pageSize;
    }
}
