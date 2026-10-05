<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Wallet;

/**
 * Target of one bulk payout item: a wallet number or an IBAN.
 */
final class WalletBulkTargetType
{
    public const WALLET = 'WALLET';
    public const IBAN = 'IBAN';

    public const ALL = [
        self::WALLET,
        self::IBAN,
    ];

    private function __construct()
    {
    }
}
