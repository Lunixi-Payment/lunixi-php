<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Fraud;

/**
 * A list of persisted fraud decision logs (response of GET /fraud/decision-logs).
 * The endpoint is limit-based (no cursor); items map to FraudDecision.
 */
final class FraudDecisionList
{
    /** @var FraudDecision[] */
    private array $items;

    /** @param array<string,mixed> $response The full list envelope. */
    public function __construct(array $response)
    {
        $data = isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
        $this->items = array_map(
            static fn ($row): FraudDecision => new FraudDecision(is_array($row) ? $row : []),
            array_values($data)
        );
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
}
