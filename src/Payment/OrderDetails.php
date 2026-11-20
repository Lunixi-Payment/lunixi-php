<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

use Lunixi\Sdk\Exception\ConfigurationException;

/**
 * Order details every payment endpoint requires: the buyer, the billing address
 * and at least one basket item; a basket with a `PHYSICAL` item also requires
 * the shipping address. Payment providers do not start a payment without them.
 *
 * The gateway refuses a missing field with `payment.order.field_required` and
 * an invalid one with `payment.order.field_invalid` (OrderFieldErrorCode);
 * `details.field` is the dotted path of the first failing field. The SDK checks
 * the same rule before sending and names the same paths. Same values as the
 * Node SDK's `order-details-values.js`.
 */
final class OrderDetails
{
    /** Order objects required on every payment endpoint. */
    public const ORDER_DETAILS_REQUIRED_FIELDS = ['buyer', 'billingAddress', 'basketItems'];

    /** `buyer` fields that must be non-empty strings (`identityNumber`: Turkish ID or passport; `ip`: the buyer's browser). */
    public const BUYER_REQUIRED_FIELDS = [
        'name', 'surname', 'identityNumber', 'email', 'gsmNumber', 'city', 'country', 'zipCode', 'ip',
    ];

    /**
     * `buyer` fields a checkout intent may leave out (createIntent(),
     * createMarketplaceIntent()): the Lunixi form takes the IP from the buyer's
     * browser. A given `ip` is still sent and wins.
     */
    public const CHECKOUT_INTENT_BUYER_OPTIONAL_FIELDS = ['ip'];

    /** Fields of `billingAddress` and `shippingAddress` that must be non-empty strings. */
    public const ADDRESS_REQUIRED_FIELDS = ['contactName', 'address', 'city', 'country', 'zipCode'];

    /** Fields of each `basketItems[]` entry the gateway requires. */
    public const BASKET_ITEM_REQUIRED_FIELDS = ['id', 'name', 'category1', 'itemType', 'price'];

    private function __construct()
    {
    }

    /**
     * Paths of the missing order fields of a payment body, in the gateway's
     * format (`buyer`, `buyer.email`, `billingAddress.city`, `shippingAddress`).
     * An empty list means the order details are complete. `$withBasket = false`
     * checks the buyer and billing address only (card storage);
     * `$checkoutIntent = true` does not require CHECKOUT_INTENT_BUYER_OPTIONAL_FIELDS.
     *
     * @param array<string,mixed> $body
     * @return list<string>
     */
    public static function missing(array $body, bool $withBasket = true, bool $checkoutIntent = false): array
    {
        $buyerFields = $checkoutIntent
            ? array_values(array_diff(self::BUYER_REQUIRED_FIELDS, self::CHECKOUT_INTENT_BUYER_OPTIONAL_FIELDS))
            : self::BUYER_REQUIRED_FIELDS;
        $missing = array_merge(
            self::missingFieldsOf($body['buyer'] ?? null, $buyerFields, 'buyer'),
            self::missingFieldsOf($body['billingAddress'] ?? null, self::ADDRESS_REQUIRED_FIELDS, 'billingAddress'),
        );
        if (!$withBasket) {
            return $missing;
        }
        $items = $body['basketItems'] ?? null;
        if (!is_array($items) || $items === []) {
            $missing[] = 'basketItems';
            return $missing;
        }
        $physical = false;
        foreach (array_values($items) as $index => $item) {
            if (!is_array($item)) {
                $missing[] = "basketItems.{$index}";
                continue;
            }
            foreach (self::BASKET_ITEM_REQUIRED_FIELDS as $field) {
                if (!isset($item[$field]) || (is_string($item[$field]) && trim($item[$field]) === '')) {
                    $missing[] = "basketItems.{$index}.{$field}";
                }
            }
            $physical = $physical || ($item['itemType'] ?? null) === BasketItem::TYPE_PHYSICAL;
        }
        $shipping = $body['shippingAddress'] ?? null;
        if ($physical || $shipping !== null) {
            $missing = array_merge($missing, self::missingFieldsOf($shipping, self::ADDRESS_REQUIRED_FIELDS, 'shippingAddress'));
        }

        return $missing;
    }

    /**
     * Throws ConfigurationException naming the missing order fields (see missing()).
     *
     * @param array<string,mixed> $body
     * @throws ConfigurationException
     */
    public static function assertComplete(array $body, string $label, bool $withBasket = true, bool $checkoutIntent = false): void
    {
        $missing = self::missing($body, $withBasket, $checkoutIntent);
        if ($missing !== []) {
            throw new ConfigurationException(sprintf('%s requires the order details: %s.', $label, implode(', ', $missing)));
        }
    }

    /**
     * @param mixed $object
     * @param list<string> $fields
     * @return list<string>
     */
    private static function missingFieldsOf($object, array $fields, string $path): array
    {
        if (!is_array($object)) {
            return [$path];
        }
        $missing = [];
        foreach ($fields as $field) {
            if (!isset($object[$field]) || !is_string($object[$field]) || trim($object[$field]) === '') {
                $missing[] = "{$path}.{$field}";
            }
        }

        return $missing;
    }
}
