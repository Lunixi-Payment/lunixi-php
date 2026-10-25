<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/**
 * How a method behaves in a split payment (`splitBehavior` of
 * PaymentClient::listPaymentMethods() and of the split payment steps). The
 * platform orders the legs by it: credit approval, card holds, reversible
 * charges, the non-reversible charge last.
 */
final class SplitBehavior
{
    /** Approval first, the money moves at the end (credit). Always the first step. */
    public const APPROVE_THEN_DISBURSE = 'APPROVE_THEN_DISBURSE';
    /** Held first, captured at the end (card). */
    public const HOLD_CAPTURE = 'HOLD_CAPTURE';
    /** Charged during the payment and refunded if the payment fails. */
    public const CAPTURE_REVERSIBLE = 'CAPTURE_REVERSIBLE';
    /** Cannot be reversed: at most one such leg, always charged last. */
    public const CAPTURE_IRREVERSIBLE = 'CAPTURE_IRREVERSIBLE';

    private function __construct()
    {
    }
}
