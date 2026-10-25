<?php

declare(strict_types=1);

namespace Lunixi\Sdk\BankConnect;

/**
 * The rail the bank chose: own accounts (INTERNAL), same bank (INTRABANK),
 * FAST or EFT; UNKNOWN until the bank says.
 */
final class BankTransferRail
{
    public const INTERNAL = 'INTERNAL';
    public const INTRABANK = 'INTRABANK';
    public const FAST = 'FAST';
    public const EFT = 'EFT';
    public const UNKNOWN = 'UNKNOWN';

    public const ALL = [
        self::INTERNAL,
        self::INTRABANK,
        self::FAST,
        self::EFT,
        self::UNKNOWN,
    ];

    private function __construct()
    {
    }
}
