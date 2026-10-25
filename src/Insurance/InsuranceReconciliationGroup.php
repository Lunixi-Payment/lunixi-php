<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Insurance;

/** One group of the reconciliation summary: a provider connection, a currency and who collects the premium. */
final class InsuranceReconciliationGroup extends InsuranceResource
{
    public function provider(): InsuranceProvider
    {
        return new InsuranceProvider($this->object('provider'));
    }

    public function currency(): string
    {
        return $this->str('currency');
    }

    /** One of the InsurancePremiumCollectedBy constants. */
    public function premiumCollectedBy(): string
    {
        return $this->str('premiumCollectedBy');
    }

    public function totals(): InsuranceReconciliationTotals
    {
        return new InsuranceReconciliationTotals($this->object('totals'));
    }
}
