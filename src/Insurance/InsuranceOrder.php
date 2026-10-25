<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Insurance;

/**
 * An insurance order (createOrder(), getOrder(), the order operations and a
 * row of listOrders()).
 *
 * The order has four independent axes — `collectionStatus`, `policyStatus`,
 * `refundStatus`, `documentStatus` — and an overall `status` derived from them
 * on every read. An axis never erases another: a collected premium whose
 * policy could not be issued stays COLLECTED + FAILED_FINAL + REQUIRED.
 */
final class InsuranceOrder extends InsuranceResource
{
    /** `inso_…`; also the `id` of the premium basket item. */
    public function orderId(): string
    {
        return $this->str('orderId');
    }

    public function quoteId(): string
    {
        return $this->str('quoteId');
    }

    public function insuranceType(): string
    {
        return $this->str('insuranceType');
    }

    /** One of the InsuranceOrderSurface constants. */
    public function surface(): string
    {
        return $this->str('surface');
    }

    /** One of the InsuranceOrderStatus constants. */
    public function status(): string
    {
        return $this->str('status');
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

    /** One of the InsuranceDocumentStatus constants. */
    public function documentStatus(): string
    {
        return $this->str('documentStatus');
    }

    /** One of the InsurancePaymentMode constants. */
    public function paymentMode(): string
    {
        return $this->str('paymentMode');
    }

    /** One of the InsurancePremiumCollectedBy constants. */
    public function premiumCollectedBy(): string
    {
        return $this->str('premiumCollectedBy');
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

    /** The Lunixi payment bound to the order; null until bindPayment(). */
    public function paymentIntentId(): ?string
    {
        return $this->strOrNull('paymentIntentId');
    }

    public function merchantOrderId(): ?string
    {
        return $this->strOrNull('merchantOrderId');
    }

    public function provider(): InsuranceProvider
    {
        return new InsuranceProvider($this->object('provider'));
    }

    /** @return string[] */
    public function basketItemIds(): array
    {
        return $this->strings('basketItemIds');
    }

    /** @return InsuranceOrderPolicy[] */
    public function policies(): array
    {
        return $this->rows('policies', static fn (array $row): InsuranceOrderPolicy => new InsuranceOrderPolicy($row));
    }

    /** Lunixi error code (`insurance.<area>.<reason>`) of the last failure, or null. */
    public function errorCode(): ?string
    {
        return $this->strOrNull('errorCode');
    }

    /** Lunixi error code of a failed premium refund, or null. */
    public function refundErrorCode(): ?string
    {
        return $this->strOrNull('refundErrorCode');
    }

    public function refundedAt(): ?string
    {
        return $this->strOrNull('refundedAt');
    }

    public function createdAt(): string
    {
        return $this->str('createdAt');
    }

    public function updatedAt(): string
    {
        return $this->str('updatedAt');
    }
}
