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

    public function premiumAmount(): string
    {
        return $this->money('premiumAmount');
    }

    public function collectedOrderCount(): int
    {
        return $this->int('collectedOrderCount');
    }

    public function collectedPremiumAmount(): string
    {
        return $this->money('collectedPremiumAmount');
    }

    public function policyCount(): int
    {
        return $this->int('policyCount');
    }

    public function inForcePolicyCount(): int
    {
        return $this->int('inForcePolicyCount');
    }

    public function inForcePremiumAmount(): string
    {
        return $this->money('inForcePremiumAmount');
    }

    public function cancelledPolicyCount(): int
    {
        return $this->int('cancelledPolicyCount');
    }

    public function cancelledPremiumAmount(): string
    {
        return $this->money('cancelledPremiumAmount');
    }

    public function refundedOrderCount(): int
    {
        return $this->int('refundedOrderCount');
    }

    public function refundedPremiumAmount(): string
    {
        return $this->money('refundedPremiumAmount');
    }

    public function refundOpenOrderCount(): int
    {
        return $this->int('refundOpenOrderCount');
    }

    public function refundOpenPremiumAmount(): string
    {
        return $this->money('refundOpenPremiumAmount');
    }
}
