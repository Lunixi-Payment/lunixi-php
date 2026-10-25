<?php

declare(strict_types=1);

namespace Lunixi\Sdk\BankConnect;

/**
 * A connection between your organisation and one bank (getConnection(), or a
 * row of listConnections()). Connections are created and managed in the
 * Lunixi dashboard; the API reads them.
 */
final class BankConnectConnection extends BankConnectResource
{
    public function connectionId(): string
    {
        return $this->str('connectionId');
    }

    public function bank(): BankConnectBankRef
    {
        return new BankConnectBankRef($this->object('bank'));
    }

    /** One of the BankConnectionStatus constants. */
    public function status(): string
    {
        return $this->str('status');
    }

    /** Short snake_case reason of the current status, e.g. `activated`; null when there is none. */
    public function statusReasonCode(): ?string
    {
        return $this->strOrNull('statusReasonCode');
    }

    public function createdAt(): ?string
    {
        return $this->strOrNull('createdAt');
    }

    public function activatedAt(): ?string
    {
        return $this->strOrNull('activatedAt');
    }

    /** When data was last read from the bank successfully; null before the first read. */
    public function lastSuccessfulSyncAt(): ?string
    {
        return $this->strOrNull('lastSuccessfulSyncAt');
    }

    public function accountCount(): int
    {
        return $this->int('accountCount');
    }

    /**
     * What this connection offers (BankCapability constants). OUTGOING_TRANSFER /
     * FX_EXCHANGE: transfers and currency exchange can be made on it.
     *
     * @return string[]
     */
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
