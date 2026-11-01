<?php

declare(strict_types=1);

namespace Lunixi\Sdk\PhysicalPos;

/** `reversalStatus` of a root payment. */
final class PhysicalPosReversalStatus
{
    public const NONE = 'NONE';
    public const PARTIALLY_REFUNDED = 'PARTIALLY_REFUNDED';
    public const REFUNDED = 'REFUNDED';
    public const VOIDED = 'VOIDED';
    public const PREAUTH_CLOSED = 'PREAUTH_CLOSED';
    public const PREAUTH_CANCELLED = 'PREAUTH_CANCELLED';
    public const PREAUTH_EXPIRED = 'PREAUTH_EXPIRED';

    public const ALL = [
        self::NONE, self::PARTIALLY_REFUNDED, self::REFUNDED, self::VOIDED,
        self::PREAUTH_CLOSED, self::PREAUTH_CANCELLED, self::PREAUTH_EXPIRED,
    ];

    private function __construct()
    {
    }
}
