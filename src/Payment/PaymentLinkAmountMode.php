<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/**
 * Who decides the amount of a payment link. Fixed when the link is created.
 */
final class PaymentLinkAmountMode
{
    /** The merchant sets `amountMinor`. */
    public const FIXED = 'FIXED';
    /** The buyer enters the amount, bounded by `minAmountMinor` / `maxAmountMinor`. */
    public const OPEN = 'OPEN';
    /** The buyer picks quantities of `items`; the amount is the sum of the lines. */
    public const ITEMIZED = 'ITEMIZED';

    public const ALL = [self::FIXED, self::OPEN, self::ITEMIZED];

    private function __construct()
    {
    }
}
