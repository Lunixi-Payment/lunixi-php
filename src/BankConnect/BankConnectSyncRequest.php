<?php

declare(strict_types=1);

namespace Lunixi\Sdk\BankConnect;

/**
 * The answer to requestSync() (HTTP 202).
 *
 * QUEUED: a read from the bank starts now or is already waiting. SCHEDULED:
 * the minimum interval has not passed yet, the read runs at `scheduledAt`. The
 * outcome is not reported separately: new transactions arrive by the
 * `bank_account.transaction.received` webhook, otherwise the account's
 * `lastSuccessfulSyncAt` moves forward.
 */
final class BankConnectSyncRequest extends BankConnectResource
{
    public function accountId(): string
    {
        return $this->str('accountId');
    }

    /** One of the BankSyncStatus constants. */
    public function syncStatus(): string
    {
        return $this->str('syncStatus');
    }

    public function isQueued(): bool
    {
        return $this->syncStatus() === BankSyncStatus::QUEUED;
    }

    public function scheduledAt(): ?string
    {
        return $this->strOrNull('scheduledAt');
    }

    public function lastSuccessfulSyncAt(): ?string
    {
        return $this->strOrNull('lastSuccessfulSyncAt');
    }

    public function minSyncIntervalSeconds(): int
    {
        return $this->int('minSyncIntervalSeconds');
    }
}
