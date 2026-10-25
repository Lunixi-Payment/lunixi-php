<?php

declare(strict_types=1);

namespace Lunixi\Sdk\BankConnect;

/**
 * One cursor page of a bank-account list.
 *
 * Rows are `data.items`; paging is the envelope's top-level `pageInfo`
 * (`{hasMore, nextCursor}`), a sibling of `data`. Pass {@see nextCursor()}
 * back verbatim as the `cursor` filter for the next page; a cursor that was
 * changed is 400 `bank_connect.cursor.invalid`. These lists do not count rows.
 *
 * @template T
 */
final class BankConnectPage
{
    /** @var array<int,T> */
    private array $items;

    private bool $hasMore;

    private ?string $nextCursor;

    /**
     * @param array<string,mixed> $response The full list envelope.
     * @param callable(array<string,mixed>):T $factory Maps one row.
     */
    public function __construct(array $response, callable $factory)
    {
        $data = isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
        $rows = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];
        $this->items = array_values(array_map(
            static fn (array $row) => $factory($row),
            array_values(array_filter($rows, 'is_array'))
        ));

        $pageInfo = $response['pageInfo'] ?? ($data['pageInfo'] ?? []);
        $pageInfo = is_array($pageInfo) ? $pageInfo : [];

        $cursor = $pageInfo['nextCursor'] ?? null;
        $this->nextCursor = is_string($cursor) && $cursor !== '' ? $cursor : null;
        $this->hasMore = ($pageInfo['hasMore'] ?? false) === true && $this->nextCursor !== null;
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
}
