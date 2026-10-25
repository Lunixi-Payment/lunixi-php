<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

use Lunixi\Sdk\Exception\ConfigurationException;

/**
 * Base body of the marketplace split charge
 * (MarketplaceClient::chargeMarketplaceCard2d/3d → POST /api/v1/payments/marketplace/2d|3d),
 * `withPreauth()` included. Raw card direct API is PCI SAQ-D territory.
 *
 * It is not a single-merchant card charge: the direct 2D/3D card endpoints were
 * removed. A single-merchant card is paid with PaymentClient::directMethodPayment()
 * and DirectMethodPaymentRequest::withCard() (withForce3D(true) for 3D Secure).
 *
 * `amount` is the only amount field: the basket total in minor units (an
 * integer of at least 1), without any installment fee; the basket items'
 * `price` values must add up to it. For an installment charge send
 * withInstallment($count, $quoteToken) with the quote from
 * PaymentClient::installmentOptions(): the system adds the installment fee to
 * the charged amount.
 *
 * Lunixi requires the commercial context for these charges: card + buyer +
 * billing address + basket items. A card alone is not enough to build a
 * traceable, fraud-scored, auditable payment.
 */
final class DirectPaymentRequest
{
    /** @var array<string,mixed> */
    private array $data;

    /**
     * @param BasketItem[] $basketItems
     */
    public function __construct(
        int $amount,
        string $currency,
        string $orderId,
        CardDetails $card,
        Buyer $buyer,
        Address $billingAddress,
        array $basketItems
    )
    {
        if ($amount < 1) {
            throw new ConfigurationException("DirectPaymentRequest 'amount' must be a positive integer (minor units).");
        }
        $currency = strtoupper($currency);
        if (!Currency::isSupported($currency)) {
            throw new ConfigurationException("Unsupported currency '{$currency}'.");
        }
        if (trim($orderId) === '') {
            throw new ConfigurationException("DirectPaymentRequest 'orderId' is required.");
        }
        if ($basketItems === []) {
            throw new ConfigurationException("DirectPaymentRequest requires at least one basket item.");
        }

        $this->data = [
            'amount' => $amount,
            'currency' => $currency,
            'orderId' => $orderId,
            'card' => $card->toArray(),
            'buyer' => $buyer->toArray(),
            'billingAddress' => $billingAddress->toArray(),
            'basketItems' => array_map(
                static fn (BasketItem $item): array => $item->toArray(),
                array_values($basketItems)
            ),
        ];
    }

    /**
     * Installment count. `$quoteToken` binds the charge to a quote from
     * PaymentClient::installmentOptions(); the system adds the quoted
     * installment fee to `amount`.
     */
    public function withInstallment(int $installment, ?string $quoteToken = null): self
    {
        $this->data['installment'] = max(1, $installment);
        if ($quoteToken !== null && $quoteToken !== '') {
            $this->data['installmentQuoteToken'] = $quoteToken;
        }
        return $this;
    }

    public function withCallbackUrl(string $url): self
    {
        $this->data['callbackUrl'] = $url;
        return $this;
    }

    /**
     * Pre-authorization. `true` holds the amount on the card and the payment
     * becomes `AUTHORIZED`; take it later with PaymentClient::capture() or
     * release it with PaymentClient::void(). `false` (or not calling this)
     * captures immediately. Sent as the typed `preauth` field.
     */
    public function withPreauth(bool $preauth): self
    {
        $this->data['preauth'] = $preauth;
        return $this;
    }

    public function withPaymentChannel(string $channel): self
    {
        $this->data['paymentChannel'] = $channel;
        return $this;
    }

    public function withBasketId(string $basketId): self
    {
        $this->data['basketId'] = $basketId;
        return $this;
    }

    public function withPaymentGroup(string $group): self
    {
        $this->data['paymentGroup'] = $group;
        return $this;
    }

    public function withLocale(string $locale): self
    {
        $this->data['locale'] = $locale;
        return $this;
    }

    public function withPaymentSource(string $source): self
    {
        $this->data['paymentSource'] = $source;
        return $this;
    }

    public function withMethod(string $method): self
    {
        $this->data['method'] = $method;
        return $this;
    }

    public function withDescription(string $description): self
    {
        $this->data['description'] = $description;
        return $this;
    }

    public function withCustomerId(string $customerId): self
    {
        $this->data['customerId'] = $customerId;
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

    public function withShippingAddress(Address $address): self
    {
        $this->data['shippingAddress'] = $address->toArray();
        return $this;
    }

    /** @param BasketItem[] $items */
    public function withBasketItems(array $items): self
    {
        $this->data['basketItems'] = array_map(
            static fn (BasketItem $item): array => $item->toArray(),
            array_values($items)
        );
        return $this;
    }

    /** @param array<string,mixed> $metadata */
    public function withMetadata(array $metadata): self
    {
        $this->data['metadata'] = $metadata;
        return $this;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->data;
    }
}
