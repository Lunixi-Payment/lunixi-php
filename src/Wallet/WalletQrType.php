<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Wallet;

/**
 * Type of a wallet QR code. Static codes carry no amount; dynamic codes require one.
 */
final class WalletQrType
{
    public const P2P_STATIC = 'P2P_STATIC';
    public const P2P_DYNAMIC = 'P2P_DYNAMIC';
    public const MERCHANT_STATIC = 'MERCHANT_STATIC';
    public const MERCHANT_DYNAMIC = 'MERCHANT_DYNAMIC';

    public const ALL = [
        self::P2P_STATIC,
        self::P2P_DYNAMIC,
        self::MERCHANT_STATIC,
        self::MERCHANT_DYNAMIC,
    ];

    private function __construct()
    {
    }
}
