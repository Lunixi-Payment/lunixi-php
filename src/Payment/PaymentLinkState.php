<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/**
 * Lifecycle state of a payment link.
 *
 * COMPLETED (a single-use link was paid), EXPIRED and DEACTIVATED are final.
 * TAKEN_DOWN is set by Lunixi; every change to such a link is refused with
 * 409 `payment_link.link.taken_down`.
 */
final class PaymentLinkState
{
    public const ACTIVE = 'ACTIVE';
    public const PAUSED = 'PAUSED';
    public const COMPLETED = 'COMPLETED';
    public const EXPIRED = 'EXPIRED';
    public const DEACTIVATED = 'DEACTIVATED';
    public const TAKEN_DOWN = 'TAKEN_DOWN';

    public const ALL = [
        self::ACTIVE,
        self::PAUSED,
        self::COMPLETED,
        self::EXPIRED,
        self::DEACTIVATED,
        self::TAKEN_DOWN,
    ];

    private function __construct()
    {
    }
}
