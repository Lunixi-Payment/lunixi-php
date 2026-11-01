<?php

declare(strict_types=1);

namespace Lunixi\Sdk\PhysicalPos;

/**
 * Payment operations. Roots start with createPayment(); reversals with
 * createReversal(). Informative: the gateway decides what is valid.
 */
final class PhysicalPosPaymentOperation
{
    public const SALE = 'SALE';
    public const PREAUTH_OPEN = 'PREAUTH_OPEN';
    /** A refund that is not tied to a sale (merchant setting, off by default). */
    public const REFUND_UNMATCHED = 'REFUND_UNMATCHED';
    public const VOID = 'VOID';
    public const REFUND = 'REFUND';
    public const PREAUTH_CLOSE = 'PREAUTH_CLOSE';
    public const PREAUTH_CANCEL = 'PREAUTH_CANCEL';

    /** Operations of createPayment(). */
    public const ROOT = [self::SALE, self::PREAUTH_OPEN, self::REFUND_UNMATCHED];

    /** Operations of createReversal(). */
    public const REVERSAL = [self::VOID, self::REFUND, self::PREAUTH_CLOSE, self::PREAUTH_CANCEL];

    public const ALL = [
        self::SALE, self::PREAUTH_OPEN, self::REFUND_UNMATCHED,
        self::VOID, self::REFUND, self::PREAUTH_CLOSE, self::PREAUTH_CANCEL,
    ];

    private function __construct()
    {
    }
}
