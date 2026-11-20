<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

use Lunixi\Sdk\Exception\ConfigurationException;

/**
 * Body for POST /api/v1/payments/cards (verify-and-store): a 3D Secure
 * authorization is taken and released, no payment. The buyer and billing
 * address are required (no basket); the card's later charges take the buyer
 * from this first payment.
 */
final class StoreCardRequest
{
    /** @var array<string,mixed> */
    private array $data;

    public function __construct(CardDetails $card, string $cardUserKey, string $callbackUrl)
    {
        if (trim($cardUserKey) === '') {
            throw new ConfigurationException("StoreCardRequest 'cardUserKey' is required.");
        }
        if (trim($callbackUrl) === '') {
            throw new ConfigurationException("StoreCardRequest 'callbackUrl' is required.");
        }

        $this->data = [
            'card' => $card->toArray(),
            'cardUserKey' => $cardUserKey,
            'callbackUrl' => $callbackUrl,
        ];
    }

    public function withCurrency(string $currency): self
    {
        $this->data['currency'] = strtoupper($currency);
        return $this;
    }

    public function withBuyer(Buyer $buyer): self
    {
        $this->data['buyer'] = $buyer->toArray();
        return $this;
    }

    public function withBillingAddress(Address $address): self
    {
        $this->data['billingAddress'] = $address->toArray();
        return $this;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->data;
    }
}
