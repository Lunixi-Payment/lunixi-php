<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Wallet;

/**
 * Image format of a wallet QR code (`WalletQr::image()`).
 */
final class WalletQrImageFormat
{
    public const SVG = 'svg';
    public const PNG = 'png';

    public const ALL = [
        self::SVG,
        self::PNG,
    ];

    private function __construct()
    {
    }
}
