<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/**
 * `providerSelection` of a method in PaymentClient::listPaymentMethods().
 */
final class ProviderSelection
{
    /** Card: routing picks the provider; `providerCode` is not sent. */
    public const ROUTED = 'ROUTED';
    /**
     * `providerCode` may be omitted: one provider is connected, or the platform
     * picks the provider (`providerSelectionMode` PLATFORM).
     */
    public const OPTIONAL = 'OPTIONAL';
    /** The buyer picks the brand and more than one provider is connected; `providerCode` must be sent. */
    public const REQUIRED = 'REQUIRED';

    private function __construct()
    {
    }
}
