<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Insurance;

/**
 * How the premium of an order is paid (`paymentMode`): in a Lunixi payment
 * (`bindPayment()`), outside Lunixi (`confirmPayment()`), or not yet.
 */
final class InsurancePaymentMode
{
    public const LUNIXI_PAYMENT = 'LUNIXI_PAYMENT';
    public const EXTERNAL_PAYMENT = 'EXTERNAL_PAYMENT';
    public const NO_PAYMENT = 'NO_PAYMENT';

    public const ALL = [
        self::LUNIXI_PAYMENT,
        self::EXTERNAL_PAYMENT,
        self::NO_PAYMENT,
    ];

    private function __construct()
    {
    }
}
