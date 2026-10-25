<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Insurance;

/**
 * Status of a quote option (`status`). Only a QUOTED option that has not
 * expired (`expiresAt`) becomes an order.
 */
final class InsuranceQuoteStatus
{
    public const REQUESTED = 'REQUESTED';
    public const QUOTED = 'QUOTED';
    public const EXPIRED = 'EXPIRED';
    public const REJECTED = 'REJECTED';
    public const ERROR = 'ERROR';

    public const ALL = [
        self::REQUESTED,
        self::QUOTED,
        self::EXPIRED,
        self::REJECTED,
        self::ERROR,
    ];

    private function __construct()
    {
    }
}
