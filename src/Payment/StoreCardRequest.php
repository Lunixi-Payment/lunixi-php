<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

use Lunixi\Sdk\Exception\ConfigurationException;

/** Body for POST /api/v1/payments/cards (verify-and-store). */
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

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->data;
    }
}
