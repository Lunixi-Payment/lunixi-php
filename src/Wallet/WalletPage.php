<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Wallet;

use Generator;
use Lunixi\Sdk\Http\Envelope;

/**
 * One page of a wallet list.
 *
 * Wallet lists answer `{items, nextCursor}`; operations answer
 * `{operations, nextCursor}`. Both are read through {@see Envelope}, and a
 * `pageInfo` beside `data` (or inside it) is honoured when the gateway adds
 * one. Pass {@see nextCursor()} back verbatim as the `cursor` filter to read
 * the next page; the cursor is opaque.
 */
final class WalletPage
{
    /** @var array<int,array<string,mixed>> */
    private array $items;

    private ?string $nextCursor;

    private bool $hasMore;

    /** @var array<string,mixed> */
    private array $raw;

    /**
     * @param array<string,mixed> $response The full list envelope.
     */
    public function __construct(array $response)
    {
        $this->raw = Envelope::data($response);
        $this->items = Envelope::items($response);

        $pageInfo = $response['pageInfo'] ?? ($this->raw['pageInfo'] ?? null);
        $pageInfo = is_array($pageInfo) ? $pageInfo : [];

        $cursor = Envelope::nextCursor($response);
        if ($cursor === null && isset($pageInfo['nextCursor']) && is_string($pageInfo['nextCursor']) && $pageInfo['nextCursor'] !== '') {
            $cursor = $pageInfo['nextCursor'];
        }
        $this->nextCursor = $cursor;
        $this->hasMore = is_bool($pageInfo['hasMore'] ?? null) ? $pageInfo['hasMore'] : $cursor !== null;
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

    /** True when a further page exists. */
    public function hasMore(): bool
    {
        return $this->hasMore;
    }

    /** @return array<string,mixed> The unwrapped `data` of the answer, as received. */
    public function raw(): array
    {
        return $this->raw;
    }

    /**
     * Every row of a list, following the cursors page by page until a page
     * comes back without one. The sub-clients' `iterate()` methods use it.
     *
     * @param callable(array<string,mixed>):WalletPage $fetchPage Reads one page for the given filters.
     * @param array<string,mixed>                      $filters   Filters of the first page; `cursor` is replaced per page.
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
