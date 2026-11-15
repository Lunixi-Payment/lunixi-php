<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/**
 * A bank the buyer can pay with in a bank-selection method (OPEN_BANKING,
 * SHOPPING_LOAN): `methods[].banks[]` of listPaymentMethods(). Only banks open
 * in the agreement permissions of the merchant's connections are listed.
 */
final class PaymentMethodBank
{
    /** @var array<string,mixed> */
    private array $data;

    /** @param array<string,mixed> $data */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /** 4-digit BKM bank code (a string: "0062"); send it as the block's `bankCode`. */
    public function bankCode(): string
    {
        return (string) ($this->data['bankCode'] ?? '');
    }

    /** Bank name to show the buyer. */
    public function bankName(): string
    {
        return (string) ($this->data['bankName'] ?? '');
    }

    /**
     * The provider the platform picks for this bank when only `bankCode` is
     * sent (for information; another provider that offers the bank may be
     * sent as `providerCode`).
     */
    public function providerCode(): string
    {
        return (string) ($this->data['providerCode'] ?? '');
    }

    /** @return array<string,mixed> */
    public function raw(): array
    {
        return $this->data;
    }
}
