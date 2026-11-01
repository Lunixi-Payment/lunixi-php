<?php

declare(strict_types=1);

namespace Lunixi\Sdk\PhysicalPos;

/**
 * getSalesSummary(): approved sales, refunds and voids with the net, per
 * currency, grouped by day, store, device or instrument. Ambiguous payments
 * (UNKNOWN, MANUAL_REVIEW) are counted only.
 */
final class PhysicalPosSalesSummary extends PhysicalPosResource
{
    /** `TEST` or `LIVE` (the key's environment). */
    public function environment(): string
    {
        return $this->str('environment');
    }

    public function dateFrom(): string
    {
        return $this->str('dateFrom');
    }

    public function dateTo(): string
    {
        return $this->str('dateTo');
    }

    /** `DAY`, `STORE`, `DEVICE` or `INSTRUMENT`. */
    public function groupBy(): string
    {
        return $this->str('groupBy');
    }

    /**
     * Rows `{groupKey (null = device without a store), currency, saleCount, saleMinor,
     * refundCount, refundMinor, voidCount, voidMinor, netMinor, ambiguousCount}`.
     *
     * @return array<int,array<string,mixed>>
     */
    public function rows(): array
    {
        return $this->list('rows');
    }
}
