<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/**
 * State of one buyer's payment attempt on a link.
 *
 * OPEN and BOUND are in progress (BOUND = the checkout form is open);
 * SUCCEEDED is paid. NEEDS_REVIEW is held for reconciliation by Lunixi.
 */
final class PaymentLinkAttemptState
{
    public const OPEN = 'OPEN';
    public const BOUND = 'BOUND';
    public const SUCCEEDED = 'SUCCEEDED';
    public const FAILED = 'FAILED';
    public const EXPIRED = 'EXPIRED';
    public const ABANDONED = 'ABANDONED';
    public const NEEDS_REVIEW = 'NEEDS_REVIEW';

    public const ALL = [
        self::OPEN,
        self::BOUND,
        self::SUCCEEDED,
        self::FAILED,
        self::EXPIRED,
        self::ABANDONED,
        self::NEEDS_REVIEW,
    ];

    private function __construct()
    {
    }
}
