<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/**
 * ISO-4217 currency codes supported by the gateway (mirrors the CurrencyCode enum).
 */
final class Currency
{
    public const SUPPORTED = [
        'TRY', 'USD', 'EUR', 'GBP', 'RUB', 'AED', 'SAR', 'QAR', 'KWD', 'JPY', 'CHF', 'CAD', 'AUD', 'CNY', 'AZN',
    ];

    public static function isSupported(string $code): bool
    {
        return in_array(strtoupper($code), self::SUPPORTED, true);
    }

    private function __construct()
    {
    }
}
