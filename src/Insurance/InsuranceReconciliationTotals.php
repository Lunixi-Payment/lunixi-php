<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Insurance;

/**
 * Totals of one reconciliation group. Amounts are minor units. In force =
 * policies ISSUED or CANCEL_PENDING; an open refund is REQUIRED, PENDING or
 * FAILED. Statuses are the current ones.
 */
final class InsuranceReconciliationTotals extends InsuranceResource
{
    public function orderCount(): int
    {
        return $this->int('orderCount');
    }

    public function premiumAmount(): int
    {
        return $this->int('premiumAmount');
    }

    public function collectedOrderCount(): int
    {
        return $this->int('collectedOrderCount');
    }

    public function collectedPremiumAmount(): int
    {
        return $this->int('collectedPremiumAmount');
    }

    public function policyCount(): int
    {
        return $this->int('policyCount');
    }

    public function inForcePolicyCount(): int
    {
        return $this->int('inForcePolicyCount');
    }

    public function inForcePremiumAmount(): int
    {
        return $this->int('inForcePremiumAmount');
    }

    public function cancelledPolicyCount(): int
    {
        return $this->int('cancelledPolicyCount');
    }

    public function cancelledPremiumAmount(): int
    {
        return $this->int('cancelledPremiumAmount');
    }

    public function refundedOrderCount(): int
    {
        return $this->int('refundedOrderCount');
    }

    public function refundedPremiumAmount(): int
    {
        return $this->int('refundedPremiumAmount');
    }

    public function refundOpenOrderCount(): int
    {
        return $this->int('refundOpenOrderCount');
    }

    public function refundOpenPremiumAmount(): int
    {
        return $this->int('refundOpenPremiumAmount');
    }
}
