<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Sanction;

use Generator;
use Lunixi\Sdk\Http\Envelope;

/**
 * One page of a sanction list.
 *
 * History, monitoring alerts and monitored entities answer
 * `{items, nextCursor}`; monitoring matches and the deny list answer
 * `{items}` with the envelope's `pageInfo` beside `data`. Both are read
 * through {@see Envelope}. Pass {@see nextCursor()} back verbatim as the
 * `cursor` filter to read the next page; the cursor is opaque.
 */
final class SanctionPage
{
    /** @var array<int,array<string,mixed>> */
    private array $items;

    private ?string $nextCursor;

    private bool $hasMore;

    private ?int $totalCount;

    /** @var array<string,mixed> */
    private array $raw;

    /** @param array<string,mixed> $response The full list envelope. */
    public function __construct(array $response)
    {
        $this->raw = Envelope::data($response);
        $this->items = Envelope::items($response);
        $pageInfo = $response['pageInfo'] ?? null;
        $this->nextCursor = Envelope::nextCursor($response) ?? self::cursorOf($pageInfo);

        $hasMore = is_array($pageInfo) ? ($pageInfo['hasMore'] ?? null) : null;
        $this->hasMore = is_bool($hasMore) ? $hasMore : $this->nextCursor !== null;

        $total = is_array($pageInfo) ? ($pageInfo['totalCount'] ?? null) : null;
        $this->totalCount = is_int($total) ? $total : Envelope::totalCount($response);
    }

    /**
     * Cursor from the `pageInfo` beside `data` (matches, deny list) — read
     * after the cursor inside `data`, the same order as the Node SDK.
     *
     * @param mixed $pageInfo
     */
    private static function cursorOf($pageInfo): ?string
    {
        if (!is_array($pageInfo)) {
            return null;
        }
        $cursor = $pageInfo['nextCursor'] ?? null;

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    /** @return array<int,array<string,mixed>> The rows of this page. */
    public function items(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    /** Opaque cursor of the next page, or null on the last page. */
    public function nextCursor(): ?string
    {
        return $this->nextCursor;
    }

    public function hasMore(): bool
    {
        return $this->hasMore;
    }

    /** Only when the gateway counted (`includeTotal: true`, the deny list). */
    public function totalCount(): ?int
    {
        return $this->totalCount;
    }

    /** @return array<string,mixed> The unwrapped `data` (history also carries its counters here). */
    public function raw(): array
    {
        return $this->raw;
    }

    /**
     * Every row of a list, following the cursors until a page comes back
     * without one.
     *
     * @param callable(array<string,mixed>):SanctionPage $fetchPage
     * @param array<string,mixed>                        $filters Filters of the first page; `cursor` is replaced per page.
     * @return Generator<int,array<string,mixed>>
     */
    public static function paginate(callable $fetchPage, array $filters): Generator
    {
        do {
            $page = $fetchPage($filters);
            foreach ($page->items() as $item) {
                yield $item;
            }
            $filters['cursor'] = $page->nextCursor();
        } while ($filters['cursor'] !== null);
    }
}
