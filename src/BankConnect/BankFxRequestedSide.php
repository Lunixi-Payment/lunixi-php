<?php

declare(strict_types=1);

namespace Lunixi\Sdk\BankConnect;

/**
 * Which amount of a quote you fixed: SOURCE (sell amount) or TARGET (buy amount).
 */
final class BankFxRequestedSide
{
    public const SOURCE = 'SOURCE';
    public const TARGET = 'TARGET';

    public const ALL = [
        self::SOURCE,
        self::TARGET,
    ];

    private function __construct()
    {
    }
}
