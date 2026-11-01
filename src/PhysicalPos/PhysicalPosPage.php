<?php

declare(strict_types=1);

namespace Lunixi\Sdk\PhysicalPos;

/**
 * One cursor page of a physical POS list (newest first).
 *
 * Rows are `data.items`; paging is the envelope's top-level `pageInfo`
 * (`{hasMore, nextCursor, totalCount}`), a sibling of `data` (a copy inside
 * `data` is read only as a fallback). Pass {@see nextCursor()} back verbatim as
 * the `cursor` filter of the next page; the cursor is opaque.
 *
 * @template T
 */
final class PhysicalPosPage
{
    /** @var array<int,T> */
    private array $items;

    private bool $hasMore;

    private ?string $nextCursor;

    private ?int $totalCount;

    /**
     * @param array<string,mixed> $response The full list envelope.
     * @param callable(array<string,mixed>):T|null $factory Maps one row; null keeps the row array.
     */
    public function __construct(array $response, ?callable $factory = null)
    {
        $data = isset($response['data']) && is_array($response['data']) ? $response['data'] : $response;
        $rows = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];
        $rows = array_values(array_filter($rows, 'is_array'));
        $this->items = $factory === null ? $rows : array_values(array_map(
            static fn (array $row) => $factory($row),
            $rows
        ));

        $pageInfo = $response['pageInfo'] ?? ($data['pageInfo'] ?? []);
        $pageInfo = is_array($pageInfo) ? $pageInfo : [];

        $cursor = $pageInfo['nextCursor'] ?? null;
        $this->nextCursor = is_string($cursor) && $cursor !== '' ? $cursor : null;
        $this->hasMore = ($pageInfo['hasMore'] ?? false) === true && $this->nextCursor !== null;
        $total = $pageInfo['totalCount'] ?? null;
        $this->totalCount = is_int($total) ? $total : null;
    }

    /** @return array<int,T> */
    public function items(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function hasMore(): bool
    {
        return $this->hasMore;
    }

    /** Opaque cursor of the next page, or null on the last page. Do not build or modify it. */
    public function nextCursor(): ?string
    {
        return $this->nextCursor;
    }

    /**
     * Filtered row count — only on the entity lists that accept
     * `includeTotal: true` (stores, registers, terminals, terminal acquirers,
     * devices). Null otherwise; payments and transactions are never counted.
     */
    public function totalCount(): ?int
    {
        return $this->totalCount;
    }
}
