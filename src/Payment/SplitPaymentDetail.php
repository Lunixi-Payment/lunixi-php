<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/**
 * A split payment from PaymentClient::getSplitPayment() (with legs) or an item
 * of listSplitPayments() (without legs): status, money aggregate, refund and
 * chargeback summary.
 */
final class SplitPaymentDetail
{
    /** @var array<string,mixed> */
    private array $data;

    /** @param array<string,mixed> $data The split payment object (response `data` or a list item). */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function id(): string
    {
        return (string) ($this->data['id'] ?? '');
    }

    public function orderId(): ?string
    {
        $value = $this->data['orderId'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** One of the SplitPaymentStatus::* values. */
    public function status(): string
    {
        return (string) ($this->data['status'] ?? '');
    }

    public function currency(): string
    {
        return (string) ($this->data['currency'] ?? '');
    }

    public function needsReconciliation(): bool
    {
        return ($this->data['needsReconciliation'] ?? false) === true;
    }

    /**
     * totalAmount, legsAmount, amountsBalanced, legCount, capturedAmount,
     * authorizedAmount, compensatedAmount, legStatusBreakdown.
     *
     * @return array<string,mixed>
     */
    public function aggregate(): array
    {
        return is_array($this->data['aggregate'] ?? null) ? $this->data['aggregate'] : [];
    }

    /** @return SplitPaymentLeg[] Empty for a list item. */
    public function legs(): array
    {
        $legs = is_array($this->data['legs'] ?? null) ? $this->data['legs'] : [];

        return array_values(array_map(
            static fn ($leg): SplitPaymentLeg => new SplitPaymentLeg(is_array($leg) ? $leg : []),
            $legs
        ));
    }

    /** What can still be refunded from the main payment (minor units). */
    public function refundableAmount(): int
    {
        return (int) ($this->summary('refund')['refundableAmount'] ?? 0);
    }

    /** Amount of the legs that cannot be reversed (never refunded). */
    public function nonRefundableAmount(): int
    {
        return (int) ($this->summary('refund')['nonRefundableAmount'] ?? 0);
    }

    public function refundedAmount(): int
    {
        return (int) ($this->summary('refund')['refundedAmount'] ?? 0);
    }

    /** A leg has a chargeback; the main payment's status does not change. */
    public function hasDispute(): bool
    {
        return ($this->summary('dispute')['hasDispute'] ?? false) === true;
    }

    public function disputedAmount(): int
    {
        return (int) ($this->summary('dispute')['disputedAmount'] ?? 0);
    }

    /** @return array<string,mixed> */
    public function raw(): array
    {
        return $this->data;
    }

    /** @return array<string,mixed> */
    private function summary(string $key): array
    {
        return is_array($this->data[$key] ?? null) ? $this->data[$key] : [];
    }
}
