<?php

declare(strict_types=1);

namespace Lunixi\Sdk\PhysicalPos;

/**
 * getTaxSummary(): the tax breakdown computed from the sale items, with
 * refunds and voids deducted, plus the amount totals of itemized and
 * itemless payments (ITEMIZED.netMinor + ITEMLESS.netMinor = the sales
 * summary net for the same window and filters).
 */
final class PhysicalPosTaxSummary extends PhysicalPosResource
{
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

    /**
     * Rows `{taxType, rateBp (null for a fixed amount), currency, saleBaseMinor, saleTaxMinor,
     * refundBaseMinor, refundTaxMinor, voidBaseMinor, voidTaxMinor, netBaseMinor, netTaxMinor}`.
     *
     * @return array<int,array<string,mixed>>
     */
    public function rows(): array
    {
        return $this->list('rows');
    }

    /**
     * `{currency, scope (ITEMIZED|ITEMLESS), saleCount, saleMinor, refundCount, refundMinor,
     * voidCount, voidMinor, netMinor}`.
     *
     * @return array<int,array<string,mixed>>
     */
    public function amountTotals(): array
    {
        return $this->list('amountTotals');
    }
}
