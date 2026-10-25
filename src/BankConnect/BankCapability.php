<?php

declare(strict_types=1);

namespace Lunixi\Sdk\BankConnect;

/**
 * What a bank or a connection offers. OUTGOING_TRANSFER and FX_EXCHANGE: the
 * connection can send transfers (BankTransferClient) and exchange currency
 * (BankFxClient).
 */
final class BankCapability
{
    public const ACCOUNT_LIST = 'ACCOUNT_LIST';
    public const BALANCE = 'BALANCE';
    public const TRANSACTIONS = 'TRANSACTIONS';
    public const OUTGOING_TRANSFER = 'OUTGOING_TRANSFER';
    public const FX_EXCHANGE = 'FX_EXCHANGE';

    public const ALL = [
        self::ACCOUNT_LIST,
        self::BALANCE,
        self::TRANSACTIONS,
        self::OUTGOING_TRANSFER,
        self::FX_EXCHANGE,
    ];

    private function __construct()
    {
    }
}
