<?php

declare(strict_types=1);

namespace Lunixi\Sdk\BankConnect;

/**
 * Answer of BankFxClient::listRates().
 */
final class BankFxRates extends BankConnectResource
{
    /** @return BankFxRate[] */
    public function items(): array
    {
        $rows = $this->data['items'] ?? [];
        return is_array($rows)
            ? array_values(array_map(static fn (array $row): BankFxRate => new BankFxRate($row), array_values(array_filter($rows, 'is_array'))))
            : [];
    }

    /** How often the bank refreshes the rates, in seconds. */
    public function refreshSeconds(): int
    {
        return $this->int('refreshSeconds');
    }

    public function fetchedAt(): ?string
    {
        return $this->strOrNull('fetchedAt');
    }
}
