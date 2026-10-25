<?php

declare(strict_types=1);

namespace Lunixi\Sdk\BankConnect;

/**
 * Direction of a bank transaction: money in (CREDIT) or out (DEBIT).
 */
final class BankTransactionDirection
{
    public const CREDIT = 'CREDIT';
    public const DEBIT = 'DEBIT';

    public const ALL = [
        self::CREDIT,
        self::DEBIT,
    ];

    private function __construct()
    {
    }
}
