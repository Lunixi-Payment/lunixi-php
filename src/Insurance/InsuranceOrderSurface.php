<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Insurance;

/**
 * Where an order was created (`surface`).
 */
final class InsuranceOrderSurface
{
    public const MERCHANT_API = 'MERCHANT_API';
    public const CHECKOUT_FORM = 'CHECKOUT_FORM';
    public const PANEL = 'PANEL';

    public const ALL = [
        self::MERCHANT_API,
        self::CHECKOUT_FORM,
        self::PANEL,
    ];

    private function __construct()
    {
    }
}
