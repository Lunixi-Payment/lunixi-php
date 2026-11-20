<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/**
 * `details.reason` of an order field error (OrderFieldErrorCode). Same values
 * as the Node SDK's `OrderFieldReason`.
 */
final class OrderFieldReason
{
    public const REQUIRED = 'required';
    /** The basket has a `PHYSICAL` item and `shippingAddress` is missing. */
    public const REQUIRED_WHEN_PHYSICAL = 'required_when_physical';
    public const INVALID_TYPE = 'invalid_type';
    public const INVALID_FORMAT = 'invalid_format';
    public const TOO_LONG = 'too_long';
    /** `basketItems` is an empty array. */
    public const TOO_FEW_ITEMS = 'too_few_items';

    private function __construct()
    {
    }
}
