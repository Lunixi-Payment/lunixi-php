<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Insurance;

/**
 * Who collects the premium of a quote option or an order (`premiumCollectedBy`).
 *
 * MERCHANT: you collect it, in your Lunixi payment (the premium basket item) or
 * outside Lunixi (`confirmPayment()`). PROVIDER: the insurance provider
 * collects it; such an order cannot be completed through the API yet.
 */
final class InsurancePremiumCollectedBy
{
    public const MERCHANT = 'MERCHANT';
    public const PROVIDER = 'PROVIDER';

    public const ALL = [
        self::MERCHANT,
        self::PROVIDER,
    ];

    private function __construct()
    {
    }
}
