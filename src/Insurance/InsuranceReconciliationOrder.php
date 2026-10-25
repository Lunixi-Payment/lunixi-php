<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Insurance;

/** A row of listReconciliationOrders(): an order and its policies, newest first. */
final class InsuranceReconciliationOrder extends InsuranceResource
{
    public function orderId(): string
    {
        return $this->str('orderId');
    }

    public function merchantOrderId(): ?string
    {
        return $this->strOrNull('merchantOrderId');
    }

    public function paymentIntentId(): ?string
    {
        return $this->strOrNull('paymentIntentId');
    }

    /** Your payment id of a premium collected outside Lunixi, or null. */
    public function externalPaymentId(): ?string
    {
        return $this->strOrNull('externalPaymentId');
    }

    public function insuranceType(): string
    {
        return $this->str('insuranceType');
    }

    public function connectionId(): string
    {
        return $this->str('connectionId');
    }

    /** Lunixi's catalogue code of the provider. */
    public function providerCode(): string
    {
        return $this->str('providerCode');
    }

    /** One of the InsurancePremiumCollectedBy constants. */
    public function premiumCollectedBy(): string
    {
        return $this->str('premiumCollectedBy');
    }

    /** One of the InsurancePaymentMode constants. */
    public function paymentMode(): string
    {
        return $this->str('paymentMode');
    }

    /** Minor units. */
    public function premiumAmount(): int
    {
        return $this->int('premiumAmount');
    }

    public function currency(): string
    {
        return $this->str('currency');
    }

    /** One of the InsuranceCollectionStatus constants. */
    public function collectionStatus(): string
    {
        return $this->str('collectionStatus');
    }

    /** One of the InsurancePolicyStatus constants. */
    public function policyStatus(): string
    {
        return $this->str('policyStatus');
    }

    /** One of the InsuranceRefundStatus constants. */
    public function refundStatus(): string
    {
        return $this->str('refundStatus');
    }

    public function createdAt(): string
    {
        return $this->str('createdAt');
    }

    public function collectedAt(): ?string
    {
        return $this->strOrNull('collectedAt');
    }

    public function refundedAt(): ?string
    {
        return $this->strOrNull('refundedAt');
    }

    /** @return InsuranceReconciliationPolicy[] */
    public function policies(): array
    {
        return $this->rows('policies', static fn (array $row): InsuranceReconciliationPolicy => new InsuranceReconciliationPolicy($row));
    }
}
