<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/**
 * `split` of a payment row (PaymentClient::list() with `includeLegs`,
 * PaymentClient::get()): marks the main payment of a split payment or one of
 * its legs.
 */
final class PaymentSplitRef
{
    /** The main payment (one row in the list, "split" label). */
    public const ROLE_PARENT = 'PARENT';
    /** A leg. */
    public const ROLE_LEG = 'LEG';

    /** @var array<string,mixed> */
    private array $data;

    /** @param array<string,mixed> $data */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * The `split` of a payment row; null when the payment is not part of a split payment.
     *
     * @param mixed $value
     */
    public static function fromRow($value): ?self
    {
        if (!is_array($value)) {
            return null;
        }
        $id = $value['splitPaymentId'] ?? null;
        $role = $value['role'] ?? null;

        return is_string($id) && $id !== '' && is_string($role) && $role !== '' ? new self($value) : null;
    }

    public function splitPaymentId(): string
    {
        return (string) ($this->data['splitPaymentId'] ?? '');
    }

    /** ROLE_PARENT or ROLE_LEG. */
    public function role(): string
    {
        return (string) ($this->data['role'] ?? '');
    }

    public function isParent(): bool
    {
        return $this->role() === self::ROLE_PARENT;
    }

    public function isLeg(): bool
    {
        return $this->role() === self::ROLE_LEG;
    }

    /** Checkout Form main payment of a leg; null otherwise. */
    public function parentPaymentId(): ?string
    {
        $value = $this->data['parentPaymentId'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** Position of a leg; null on the main payment. */
    public function legSequence(): ?int
    {
        $value = $this->data['legSequence'] ?? null;

        return is_int($value) && $value > 0 ? $value : null;
    }
}
