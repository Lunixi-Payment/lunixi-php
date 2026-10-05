<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/**
 * How many times a payment link can be paid. Fixed when the link is created.
 */
final class PaymentLinkUsage
{
    /** Paid once — an invoice or a personal payment request. */
    public const SINGLE_USE = 'SINGLE_USE';
    /** Paid many times — donations, tickets, a product page. */
    public const MULTI_USE = 'MULTI_USE';

    public const ALL = [self::SINGLE_USE, self::MULTI_USE];

    private function __construct()
    {
    }
}
