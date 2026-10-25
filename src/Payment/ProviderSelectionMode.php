<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/**
 * `providerSelectionMode` of a method in PaymentClient::listPaymentMethods():
 * who picks the provider.
 */
final class ProviderSelectionMode
{
    /**
     * The buyer picks the brand (wallet brand, lending bank, card brand). With
     * more than one provider connected `providerCode` is required; without it
     * the gateway answers 400 `payment.provider.required`.
     */
    public const BUYER = 'BUYER';
    /**
     * The buyer does not pick a brand. `providerCode` is optional: when it is
     * omitted the platform picks one of the connected providers before any money
     * moves and keeps it for the payment. When none of them can take the payment
     * the gateway answers 422 `payment.provider.none_eligible`.
     */
    public const PLATFORM = 'PLATFORM';

    private function __construct()
    {
    }
}
