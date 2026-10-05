<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Wallet;

/**
 * KYC level of a wallet end user; the level decides the limits that apply.
 */
final class WalletKycLevel
{
    public const NONE = 'NONE';
    public const LOW = 'LOW';
    public const SUBSTANTIAL = 'SUBSTANTIAL';
    public const HIGH = 'HIGH';

    public const ALL = [
        self::NONE,
        self::LOW,
        self::SUBSTANTIAL,
        self::HIGH,
    ];

    private function __construct()
    {
    }
}
