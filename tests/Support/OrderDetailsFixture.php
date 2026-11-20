<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Tests\Support;

use Lunixi\Sdk\Commerce\OrderSnapshot;
use Lunixi\Sdk\Payment\Address;
use Lunixi\Sdk\Payment\BasketItem;
use Lunixi\Sdk\Payment\Buyer;

/**
 * Order details every payment endpoint requires (buyer, billing address,
 * basket; shipping address for a PHYSICAL item; Payment\OrderDetails). The
 * values are fictional test values: a documentation IP (RFC 5737), a
 * placeholder identity number and an example.com address. Same values as the
 * Node SDK's `test/fixtures/order-details.js`.
 */
final class OrderDetailsFixture
{
    public const BUYER = [
        'name' => 'Ada',
        'surname' => 'Yilmaz',
        'identityNumber' => '11111111111',
        'email' => 'ada@example.com',
        'gsmNumber' => '+905350000000',
        'city' => 'Istanbul',
        'country' => 'Turkey',
        'zipCode' => '34000',
        'ip' => '203.0.113.10',
    ];

    /** Key order of Address::toArray(), so a body built by a DTO compares equal. */
    public const ADDRESS = [
        'address' => 'Example Street 1',
        'zipCode' => '34000',
        'contactName' => 'Ada Yilmaz',
        'city' => 'Istanbul',
        'country' => 'Turkey',
    ];

    public static function buyer(): Buyer
    {
        return new Buyer(self::BUYER);
    }

    public static function address(): Address
    {
        return new Address(self::ADDRESS);
    }

    /** One basket item priced `$price` (minor units), as sent (BasketItem::toArray() key order). */
    public static function basketItemArray(string $price, string $itemType = BasketItem::TYPE_VIRTUAL, string $id = 'SKU-1'): array
    {
        return ['id' => $id, 'price' => $price, 'name' => 'Demo Product', 'category1' => 'Demo', 'itemType' => $itemType];
    }

    public static function basketItem(string $price, string $itemType = BasketItem::TYPE_VIRTUAL, string $id = 'SKU-1'): BasketItem
    {
        return new BasketItem(self::basketItemArray($price, $itemType, $id));
    }

    /**
     * The order fields of a raw body: buyer, billing address and one basket
     * item priced `$amount` (plus the shipping address for a PHYSICAL item).
     * Without `$amount` there is no basket (card storage).
     *
     * @return array<string,mixed>
     */
    public static function orderDetails(?string $amount = null, string $itemType = BasketItem::TYPE_VIRTUAL): array
    {
        $order = ['buyer' => self::BUYER, 'billingAddress' => self::ADDRESS];
        if ($amount === null) {
            return $order;
        }
        $order['basketItems'] = [self::basketItemArray($amount, $itemType)];
        if ($itemType === BasketItem::TYPE_PHYSICAL) {
            $order['shippingAddress'] = self::ADDRESS;
        }

        return $order;
    }

    /**
     * A raw body completed with the order fields; the basket item is priced
     * `$amount` (default: the body's `amount`, or `totalAmount` of a split
     * payment). The body's own fields win (a body with its own `basketItems`
     * keeps them); a PHYSICAL basket without a shipping address gets one.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public static function withOrder(array $body, ?string $amount = null): array
    {
        $amount = $amount ?? (string) ($body['amount'] ?? $body['totalAmount']);
        $completed = $body + self::orderDetails($amount);
        if (!isset($completed['shippingAddress']) && self::hasPhysicalItem($completed['basketItems'])) {
            $completed['shippingAddress'] = self::ADDRESS;
        }

        return $completed;
    }

    /**
     * Completes a request builder (CreateIntentRequest, DirectMethodPaymentRequest,
     * SplitPaymentRequest, StoreCardRequest) with the buyer and billing address
     * and, when `$amount` is given, one basket item priced `$amount` (plus the
     * shipping address for a PHYSICAL item). Pass no `$amount` to keep the
     * request's own basket or to send none.
     *
     * @template T of object
     * @param T $request
     * @return T
     */
    public static function complete(object $request, ?string $amount = null, string $itemType = BasketItem::TYPE_VIRTUAL): object
    {
        $request->withBuyer(self::buyer());
        $request->withBillingAddress(self::address());
        if ($amount === null) {
            return $request;
        }
        $request->withBasketItems([self::basketItem($amount, $itemType)]);
        if ($itemType === BasketItem::TYPE_PHYSICAL) {
            $request->withShippingAddress(self::address());
        }

        return $request;
    }

    /** A snapshot with the order details; one basket item priced the order amount. */
    public static function snapshot(OrderSnapshot $order, string $itemType = BasketItem::TYPE_VIRTUAL): OrderSnapshot
    {
        $order = $order
            ->withBuyer(self::buyer())
            ->withBillingAddress(self::address())
            ->withBasketItems([self::basketItem($order->amount(), $itemType)]);

        return $itemType === BasketItem::TYPE_PHYSICAL ? $order->withShippingAddress(self::address()) : $order;
    }

    /** @param mixed $items */
    private static function hasPhysicalItem($items): bool
    {
        foreach (is_array($items) ? $items : [] as $item) {
            if (is_array($item) && ($item['itemType'] ?? null) === BasketItem::TYPE_PHYSICAL) {
                return true;
            }
        }

        return false;
    }
}
