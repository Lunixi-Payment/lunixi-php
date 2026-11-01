<?php

declare(strict_types=1);

namespace Lunixi\Sdk\PhysicalPos;

/**
 * One row of listTransactions(): a root payment with its reversals
 * (at most 50, oldest first).
 */
final class PhysicalPosTransactionTree extends PhysicalPosResource
{
    public function root(): PhysicalPosPayment
    {
        return new PhysicalPosPayment($this->object('root'));
    }

    /** @return PhysicalPosPayment[] */
    public function children(): array
    {
        return $this->payments('children');
    }

    public function childrenTruncated(): bool
    {
        return $this->bool('childrenTruncated');
    }

    /**
     * `{lineCount, refundStatus (NONE|PARTIAL|FULL), refundedMinor}`.
     *
     * @return array<string,mixed>
     */
    public function itemSummary(): array
    {
        return $this->object('itemSummary');
    }

    /** Store of the device at payment time. */
    public function storeId(): ?string
    {
        return $this->strOrNull('storeId');
    }

    /** Register of the device at payment time. */
    public function registerId(): ?string
    {
        return $this->strOrNull('registerId');
    }
}
