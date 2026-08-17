<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Marketplace;

use Lunixi\Sdk\Exception\ConfigurationException;

/**
 * One seller leg of a marketplace split — the AGGREGATE amount attributed to a
 * sub-dealer (the caller groups basket items by vendor and sums; the gateway
 * never sees the per-item→seller mapping). `amount` is in MINOR units.
 *
 *   new MarketplaceSeller('d_8f2a', 15000)   // 150.00 to this sub-dealer
 */
final class MarketplaceSeller
{
    private string $subDealerId;
    private int $amount;

    /** @var array<string,mixed>|null */
    private ?array $scenario = null;

    public function __construct(string $subDealerId, int $amount)
    {
        if (trim($subDealerId) === '') {
            throw new ConfigurationException("MarketplaceSeller 'subDealerId' is required.");
        }
        if ($amount < 1) {
            throw new ConfigurationException("MarketplaceSeller 'amount' must be a positive integer (minor units).");
        }
        $this->subDealerId = $subDealerId;
        $this->amount = $amount;
    }

    /** Optional per-request commission scenario override (CommissionScenarioInput). */
    public function withScenario(array $scenario): self
    {
        $this->scenario = $scenario;
        return $this;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $row = ['subDealerId' => $this->subDealerId, 'amountMinor' => (string) $this->amount];
        if ($this->scenario !== null) {
            $row['scenario'] = $this->scenario;
        }
        return $row;
    }
}
