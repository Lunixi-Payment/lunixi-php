<?php

declare(strict_types=1);

namespace Lunixi\Sdk\BankConnect;

/**
 * Lunixi's bank-independent transaction type (`canonicalType`).
 */
final class BankTransactionType
{
    public const TRANSFER_IN = 'TRANSFER_IN';
    public const TRANSFER_OUT = 'TRANSFER_OUT';
    public const INTERNAL_IN = 'INTERNAL_IN';
    public const INTERNAL_OUT = 'INTERNAL_OUT';
    public const POS_SETTLEMENT = 'POS_SETTLEMENT';
    public const POS_SETTLEMENT_REFUND = 'POS_SETTLEMENT_REFUND';
    public const FEE = 'FEE';
    public const TAX = 'TAX';
    public const CASH_IN = 'CASH_IN';
    public const CASH_OUT = 'CASH_OUT';
    public const CARD = 'CARD';
    public const LOAN = 'LOAN';
    public const FX = 'FX';
    public const INTEREST = 'INTEREST';
    public const OTHER = 'OTHER';

    public const ALL = [
        self::TRANSFER_IN,
        self::TRANSFER_OUT,
        self::INTERNAL_IN,
        self::INTERNAL_OUT,
        self::POS_SETTLEMENT,
        self::POS_SETTLEMENT_REFUND,
        self::FEE,
        self::TAX,
        self::CASH_IN,
        self::CASH_OUT,
        self::CARD,
        self::LOAN,
        self::FX,
        self::INTEREST,
        self::OTHER,
    ];

    private function __construct()
    {
    }
}
