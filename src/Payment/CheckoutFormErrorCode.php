<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/**
 * Checkout Form error codes of payment options and the order lock
 * (getting-started/errors#<code>). Same values as the Node SDK's
 * `CheckoutFormErrorCode`.
 */
final class CheckoutFormErrorCode
{
    /** 422 — the provider is turned off in your checkout form (the API still accepts it). */
    public const PROVIDER_DISABLED_IN_FORM = 'payment.provider.disabled_in_form';
    /** 409 — the buyer used the 3 method selections of the payment. */
    public const METHOD_SELECTION_ATTEMPTS_EXHAUSTED = 'payment.method.selection_attempts_exhausted';
    /** 409 — a split payment is running for the order; another method cannot start. */
    public const ORDER_LOCKED = 'payment.checkout.order_locked';

    private function __construct()
    {
    }
}
