<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

use Lunixi\Sdk\Exception\ConfigurationException;

/**
 * Builds the body for `POST /api/v1/payments/intents` (gateway CreatePaymentIntentDto).
 *
 * `amount` is in MINOR units (e.g. 1000 = 10.00 TRY) — never a float. Required:
 * amount, currency, orderId. Everything else is fluent/optional.
 *
 *   $req = (new CreateIntentRequest(1000, 'TRY', 'WC-1042'))
 *       ->withInstallment(3)
 *       ->withCallbackUrl('https://shop/checkout/callback')
 *       ->withBuyer($buyer);
 */
final class CreateIntentRequest
{
    private int $amount;
    private string $currency;
    private string $orderId;

    /** @var array<string,mixed> */
    private array $optional = [];

    public function __construct(int $amount, string $currency, string $orderId)
    {
        if ($amount < 1) {
            throw new ConfigurationException("CreateIntentRequest 'amount' must be a positive integer (minor units).");
        }
        $currency = strtoupper($currency);
        if (!Currency::isSupported($currency)) {
            throw new ConfigurationException("Unsupported currency '{$currency}'.");
        }
        if (trim($orderId) === '') {
            throw new ConfigurationException("CreateIntentRequest 'orderId' is required.");
        }

        $this->amount = $amount;
        $this->currency = $currency;
        $this->orderId = $orderId;
    }

    public function withInstallment(int $installment): self
    {
        $this->optional['installment'] = max(1, $installment);
        return $this;
    }

    public function withPrice(int $price): self
    {
        $this->optional['price'] = max(0, $price);
        return $this;
    }

    public function withPaidPrice(int $paidPrice): self
    {
        if ($paidPrice < 1) {
            throw new ConfigurationException("CreateIntentRequest 'paidPrice' must be a positive integer (minor units).");
        }
        $this->optional['paidPrice'] = $paidPrice;
        return $this;
    }

    public function withLocale(string $locale): self
    {
        $this->optional['locale'] = $locale;
        return $this;
    }

    public function withCallbackUrl(string $url): self
    {
        $this->optional['callbackUrl'] = $url;
        return $this;
    }

    public function withDescription(string $description): self
    {
        $this->optional['description'] = $description;
        return $this;
    }

    public function withCustomerId(string $customerId): self
    {
        $this->optional['customerId'] = $customerId;
        return $this;
    }

    public function withCardUserKey(string $cardUserKey): self
    {
        $this->optional['cardUserKey'] = $cardUserKey;
        return $this;
    }

    public function withPaymentGroup(string $group): self
    {
        $this->optional['paymentGroup'] = $group;
        return $this;
    }

    public function withPaymentChannel(string $channel): self
    {
        $this->optional['paymentChannel'] = $channel;
        return $this;
    }

    public function withMethod(string $method): self
    {
        $this->optional['method'] = $method;
        return $this;
    }

    public function withPaymentMethod(string $paymentMethod): self
    {
        $this->optional['paymentMethod'] = $paymentMethod;
        return $this;
    }

    public function withWalletProvider(string $walletProvider): self
    {
        $this->optional['walletProvider'] = $walletProvider;
        return $this;
    }

    public function withForce3D(bool $force3D): self
    {
        $this->optional['force3D'] = $force3D;
        return $this;
    }

    /**
     * @param array<string,mixed> $settings CreateFormSettingsOverrideDto shape.
     */
    public function withSettings(array $settings): self
    {
        $this->optional['settings'] = $settings;
        return $this;
    }

    /**
     * SALE (true, default) captures immediately; AUTHORIZE-only (false) holds the
     * funds for a later capture()/void(). The gateway reads this from metadata.
     */
    public function withAutoCapture(bool $autoCapture): self
    {
        $metadata = isset($this->optional['metadata']) && is_array($this->optional['metadata'])
            ? $this->optional['metadata']
            : [];
        $metadata['autoCapture'] = $autoCapture;
        $this->optional['metadata'] = $metadata;
        return $this;
    }

    public function withBuyer(Buyer $buyer): self
    {
        $this->optional['buyer'] = $buyer->toArray();
        return $this;
    }

    public function withBillingAddress(Address $address): self
    {
        $this->optional['billingAddress'] = $address->toArray();
        return $this;
    }

    public function withShippingAddress(Address $address): self
    {
        $this->optional['shippingAddress'] = $address->toArray();
        return $this;
    }

    /** @param BasketItem[] $items */
    public function withBasketItems(array $items): self
    {
        $this->optional['basketItems'] = array_map(
            static fn (BasketItem $item): array => $item->toArray(),
            array_values($items)
        );
        return $this;
    }

    /** @param array<string,mixed> $metadata */
    public function withMetadata(array $metadata): self
    {
        $this->optional['metadata'] = $metadata;
        return $this;
    }

    /** @return array<string,mixed> The request body for the gateway. */
    public function toArray(): array
    {
        return array_merge([
            'amount' => $this->amount,
            'currency' => $this->currency,
            'orderId' => $this->orderId,
        ], $this->optional);
    }
}
