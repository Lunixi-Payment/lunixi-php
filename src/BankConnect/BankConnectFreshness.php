<?php

declare(strict_types=1);

namespace Lunixi\Sdk\BankConnect;

/**
 * How current an account's data is.
 *
 * Data is read from the bank at most once every `minSyncIntervalSeconds`;
 * `nextSyncAvailableAt` is the earliest time a new read can start.
 * BankConnectClient::requestSync() never reads earlier than that.
 */
final class BankConnectFreshness extends BankConnectResource
{
    public function lastSuccessfulSyncAt(): ?string
    {
        return $this->strOrNull('lastSuccessfulSyncAt');
    }

    public function nextSyncAvailableAt(): ?string
    {
        return $this->strOrNull('nextSyncAvailableAt');
    }

    public function minSyncIntervalSeconds(): int
    {
        return $this->int('minSyncIntervalSeconds');
    }
}
