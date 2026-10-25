<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/**
 * A leg of PaymentClient::getSplitPayment(). The card token is never returned;
 * a card leg shows only a masked tail, the last four digits and the brand.
 */
final class SplitPaymentLeg
{
    /** @var array<string,mixed> */
    private array $data;

    /** @param array<string,mixed> $data */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function id(): string
    {
        return (string) ($this->data['id'] ?? '');
    }

    public function sequence(): int
    {
        return (int) ($this->data['sequence'] ?? 0);
    }

    /** One of the PaymentMethod::* codes; null when not reported. */
    public function paymentMethod(): ?string
    {
        return self::text($this->data['paymentMethod'] ?? null);
    }

    public function providerCode(): ?string
    {
        return self::text($this->data['providerCode'] ?? null);
    }

    /** Leg amount in minor units (part of the order amount). */
    public function legAmount(): int
    {
        return (int) ($this->data['legAmount'] ?? 0);
    }

    /** 1 = single payment. */
    public function installmentCount(): int
    {
        return (int) ($this->data['installmentCount'] ?? 1);
    }

    /** Leg amount plus the installment fee; null before the leg is charged. */
    public function chargeAmount(): ?int
    {
        return self::int($this->data['chargeAmount'] ?? null);
    }

    /** Refund ceiling of the leg; null before the leg is charged. */
    public function capturedAmount(): ?int
    {
        return self::int($this->data['capturedAmount'] ?? null);
    }

    public function refundedAmount(): ?int
    {
        return self::int($this->data['refundedAmount'] ?? null);
    }

    /** Leg status (see SplitPaymentStep::status()). */
    public function legStatus(): string
    {
        return (string) ($this->data['legStatus'] ?? '');
    }

    /** REFUND_PENDING, REFUNDED, NOT_REFUNDABLE or null. */
    public function refundState(): ?string
    {
        return self::text($this->data['refundState'] ?? null);
    }

    /**
     * Refund of the leg after a failed split payment, retried until it succeeds:
     * status (PENDING | COMPLETED), amount, attempt, requestedAt, nextAttemptAt,
     * lastErrorCode, refundedAt. Null when none.
     *
     * @return array<string,mixed>|null
     */
    public function compensationRefund(): ?array
    {
        $value = $this->data['compensationRefund'] ?? null;

        return is_array($value) ? $value : null;
    }

    /**
     * Chargeback on the leg: state, disputedAmount, openChargebackCount. Null when none.
     *
     * @return array<string,mixed>|null
     */
    public function dispute(): ?array
    {
        $value = $this->data['dispute'] ?? null;

        return is_array($value) ? $value : null;
    }

    /** The leg's payment record. */
    public function paymentIntentId(): ?string
    {
        return self::text($this->data['paymentIntentId'] ?? null);
    }

    public function cardLast4(): ?string
    {
        return self::text($this->data['cardLast4'] ?? null);
    }

    public function cardBrand(): ?string
    {
        return self::text($this->data['cardBrand'] ?? null);
    }

    /** @return array<string,mixed> */
    public function raw(): array
    {
        return $this->data;
    }

    /** @param mixed $value */
    private static function text($value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param mixed $value */
    private static function int($value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }
}
