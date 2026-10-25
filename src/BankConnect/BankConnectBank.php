<?php

declare(strict_types=1);

namespace Lunixi\Sdk\BankConnect;

/**
 * A bank of the catalogue (one row of listBanks()).
 *
 * `availability` is AVAILABLE when the bank can be connected (the connection
 * itself is started in the Lunixi dashboard) and COMING_SOON otherwise; a
 * COMING_SOON bank has no capabilities.
 */
final class BankConnectBank extends BankConnectResource
{
    public function bankId(): string
    {
        return $this->str('bankId');
    }

    /** The bank's 4-digit EFT code, e.g. `0062`. */
    public function eftCode(): string
    {
        return $this->str('eftCode');
    }

    public function name(): string
    {
        return $this->str('name');
    }

    public function shortName(): ?string
    {
        return $this->strOrNull('shortName');
    }

    public function bic(): ?string
    {
        return $this->strOrNull('bic');
    }

    /** ISO 3166-1 alpha-2, e.g. `TR`. */
    public function country(): ?string
    {
        return $this->strOrNull('country');
    }

    /** One of the BankAvailability constants. */
    public function availability(): string
    {
        return $this->str('availability');
    }

    public function isAvailable(): bool
    {
        return $this->availability() === BankAvailability::AVAILABLE;
    }

    /** @return string[] BankCapability constants. */
    public function capabilities(): array
    {
        $values = $this->data['capabilities'] ?? [];
        return is_array($values) ? array_values(array_filter($values, 'is_string')) : [];
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }
}
