<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Insurance;

/** A policy line of a reconciliation order. */
final class InsuranceReconciliationPolicy extends InsuranceResource
{
    public function policyId(): string
    {
        return $this->str('policyId');
    }

    public function basketItemId(): string
    {
        return $this->str('basketItemId');
    }

    public function policyNumber(): ?string
    {
        return $this->strOrNull('policyNumber');
    }

    /** One of the InsurancePolicyStatus constants. */
    public function policyStatus(): string
    {
        return $this->str('policyStatus');
    }

    /** Minor units. */
    public function premiumAmount(): int
    {
        return $this->int('premiumAmount');
    }

    public function cancelledAt(): ?string
    {
        return $this->strOrNull('cancelledAt');
    }
}
