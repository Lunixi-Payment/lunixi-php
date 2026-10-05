<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Fraud;

/**
 * A page of persisted fraud decision logs (response of GET /fraud/decision-logs).
 *
 * Paging is cursor-based. `pageInfo` is a SIBLING of `data` in the gateway
 * envelope (not nested inside it): `{ data: [...], pageInfo: { hasMore,
 * nextCursor, totalCount } }`. Pass {@see nextCursor()} back as the `cursor`
 * filter to fetch the following page.
 */
final class FraudDecisionList
{
    /** @var FraudDecision[] */
    private array $items;

    private bool $hasMore = false;

    private ?string $nextCursor = null;

    private ?int $totalCount = null;

    /** @param array<string,mixed> $response The full list envelope. */
    public function __construct(array $response)
    {
        $data = isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
        $this->items = array_map(
            static fn ($row): FraudDecision => new FraudDecision(is_array($row) ? $row : []),
            array_values($data)
        );

        $pageInfo = isset($response['pageInfo']) && is_array($response['pageInfo']) ? $response['pageInfo'] : [];
        $this->hasMore = (bool) ($pageInfo['hasMore'] ?? false);

        $cursor = $pageInfo['nextCursor'] ?? null;
        $this->nextCursor = is_string($cursor) && $cursor !== '' ? $cursor : null;

        $total = $pageInfo['totalCount'] ?? null;
        $this->totalCount = is_int($total) ? $total : (is_numeric($total) ? (int) $total : null);
    }

    /** @return FraudDecision[] */
    public function items(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    /** The most recent decision (first item), or null. */
    public function first(): ?FraudDecision
    {
        return $this->items[0] ?? null;
    }

    /** True when a further page exists. */
    public function hasMore(): bool
    {
        return $this->hasMore;
    }

    /**
     * Opaque cursor for the next page, or null when this is the last page.
     * Do not build or modify it — pass it back verbatim as the `cursor` filter.
     */
    public function nextCursor(): ?string
    {
        return $this->nextCursor;
    }

    /** Total matching rows when the endpoint returned a count, otherwise null. */
    public function totalCount(): ?int
    {
        return $this->totalCount;
    }
}
