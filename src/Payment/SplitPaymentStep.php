<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/**
 * A step of a split payment (`steps[]`), in the order the platform set. Card
 * data is never in a step.
 */
final class SplitPaymentStep
{
    public const KIND_CARD = 'CARD';
    public const KIND_METHOD = 'METHOD';

    /** @var array<string,mixed> */
    private array $data;

    /** @param array<string,mixed> $data */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function legId(): string
    {
        return (string) ($this->data['legId'] ?? '');
    }

    /** Execution order (1..N). */
    public function sequence(): int
    {
        return (int) ($this->data['sequence'] ?? 0);
    }

    /** KIND_CARD or KIND_METHOD. */
    public function kind(): string
    {
        return (string) ($this->data['kind'] ?? '');
    }

    public function isCard(): bool
    {
        return $this->kind() === self::KIND_CARD;
    }

    /** One of the PaymentMethod::* codes (CARD for a card leg). */
    public function paymentMethod(): string
    {
        return (string) ($this->data['paymentMethod'] ?? '');
    }

    /** The non-card leg's provider; null for a card (routing picks it). */
    public function providerCode(): ?string
    {
        return self::text($this->data['providerCode'] ?? null);
    }

    /** One of the SplitBehavior::* values. */
    public function splitBehavior(): string
    {
        return (string) ($this->data['splitBehavior'] ?? '');
    }

    /** Leg amount in minor units. */
    public function amount(): ?int
    {
        return isset($this->data['amount']) ? (int) $this->data['amount'] : null;
    }

    /** Leg status (PENDING, AUTHORIZING, AWAITING_3DS, AWAITING_ACTION, AUTHORIZED, CAPTURED, VOIDED, REFUNDED, COMPENSATING, FAILED). */
    public function status(): string
    {
        return (string) ($this->data['status'] ?? '');
    }

    /** Payment record of a non-card leg (the `paymentId` of the action endpoints); null for a card or before it starts. */
    public function paymentId(): ?string
    {
        return self::text($this->data['paymentId'] ?? null);
    }

    /** This card replaces the card leg with this id (shortfall after a failed capture). */
    public function replacesLegId(): ?string
    {
        return self::text($this->data['replacesLegId'] ?? null);
    }

    /** The payment waits at this step. */
    public function isCurrent(): bool
    {
        return ($this->data['current'] ?? false) === true;
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
}
