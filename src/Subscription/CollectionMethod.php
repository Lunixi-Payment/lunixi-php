<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Subscription;

/**
 * How a subscription's invoices are collected.
 *   AUTO_CHARGE  — charge the saved card automatically each cycle (default).
 *   SEND_INVOICE — finalise an invoice but do not auto-charge (collect manually).
 *   MANUAL       — fully merchant-driven collection.
 */
final class CollectionMethod
{
    public const AUTO_CHARGE = 'AUTO_CHARGE';
    public const SEND_INVOICE = 'SEND_INVOICE';
    public const MANUAL = 'MANUAL';

    public const ALL = [self::AUTO_CHARGE, self::SEND_INVOICE, self::MANUAL];

    private function __construct()
    {
    }
}
