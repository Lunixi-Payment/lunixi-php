<?php
declare(strict_types=1);

namespace Lunixi\Sdk\Commerce;

use Lunixi\Sdk\Payment\Address;
use Lunixi\Sdk\Payment\BasketItem;
use Lunixi\Sdk\Payment\Buyer;
use Lunixi\Sdk\Payment\Currency;

/**
 * Immutable order identity persisted before any financial API call.
 *
 * The order details (buyer, billing address, shipping address for a physical
 * item, basket items) are required by every payment and travel to the intent
 * (CheckoutService::start()); attach them with the `with*()` methods, each of
 * which returns a new snapshot. They are not part of the identity: toArray()
 * and fingerprint() stay merchant, order, amount and currency only, so a stored
 * attempt keeps matching its order.
 */
final class OrderSnapshot
{
    private string $merchantId;
    private string $orderId;
    private string $currency;
    private string $amount;
    private ?Buyer $buyer = null;
    private ?Address $billingAddress = null;
    private ?Address $shippingAddress = null;
    /** @var list<BasketItem> */
    private array $basketItems = [];

    /**
     * @param mixed $amount Canonical money string; other types are rejected before coercion.
     */
    public function __construct(string $merchantId, string $orderId, $amount, string $currency)
    {
        if (trim($merchantId) === '' || trim($orderId) === '' || strlen($orderId) > 128 || $amount === '0' || $currency !== strtoupper($currency) || !Currency::isSupported($currency)) {
            throw new \InvalidArgumentException('Merchant, order, positive amount and supported uppercase currency are required.');
        }
        Money::integer($amount);
        $this->merchantId = $merchantId;
        $this->orderId = $orderId;
        $this->amount = $amount;
        $this->currency = $currency;
    }

    public function merchantId(): string { return $this->merchantId; }
    public function orderId(): string { return $this->orderId; }
    public function currency(): string { return $this->currency; }
    public function amount(): string { return $this->amount; }
    public function buyer(): ?Buyer { return $this->buyer; }
    public function billingAddress(): ?Address { return $this->billingAddress; }
    public function shippingAddress(): ?Address { return $this->shippingAddress; }
    /** @return list<BasketItem> */
    public function basketItems(): array { return $this->basketItems; }

    public function withBuyer(Buyer $buyer): self
    {
        $copy = clone $this;
        $copy->buyer = $buyer;
        return $copy;
    }

    public function withBillingAddress(Address $address): self
    {
        $copy = clone $this;
        $copy->billingAddress = $address;
        return $copy;
    }

    /** Required when a basket item is `PHYSICAL`. */
    public function withShippingAddress(Address $address): self
    {
        $copy = clone $this;
        $copy->shippingAddress = $address;
        return $copy;
    }

    /**
     * At least one item; the `price` values add up to the order amount.
     *
     * @param list<BasketItem> $items
     */
    public function withBasketItems(array $items): self
    {
        foreach ($items as $item) {
            if (!$item instanceof BasketItem) {
                throw new \InvalidArgumentException('Basket items must be BasketItem instances.');
            }
        }
        $copy = clone $this;
        $copy->basketItems = array_values($items);
        return $copy;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['merchantId' => $this->merchantId, 'orderId' => $this->orderId, 'amount' => $this->amount, 'currency' => $this->currency];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self((string) $data['merchantId'], (string) $data['orderId'], Money::integer($data['amount']), (string) $data['currency']);
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->toArray(), JSON_THROW_ON_ERROR));
    }
}
