<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/**
 * Error codes of a missing or invalid order field (ApiException::getErrorCode();
 * docs: getting-started/errors#<code>). The response carries `details.field`
 * (dotted path), `details.reason` (OrderFieldReason) and `details.fields`.
 * Same values as the Node SDK's `OrderFieldErrorCode`.
 */
final class OrderFieldErrorCode
{
    /** 400 — the buyer, billing address, basket or (for a physical item) shipping address is missing. */
    public const FIELD_REQUIRED = 'payment.order.field_required';
    /** 400 — an order field has the wrong type, format or length. */
    public const FIELD_INVALID = 'payment.order.field_invalid';

    private function __construct()
    {
    }
}
