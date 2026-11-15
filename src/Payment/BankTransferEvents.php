<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

/**
 * Webhook events of a Para Transferi (BANK_TRANSFER) payment. The envelope is
 * the one of every `payment.*` event; the payload adds `bankTransfer`
 * (expectedMinor, receivedMinor, remainingMinor, excessMinor, currency, late,
 * transfers[]); events that open an exception also carry `exceptionId`, `kind`
 * and `amountMinor`. They do not change the payment's status: the outcome is
 * still `payment.captured` / `payment.failed`.
 *
 * Not to be confused with Lunixi\Sdk\BankConnect\BankTransferEvents (outgoing
 * transfers from a connected bank account).
 */
final class BankTransferEvents
{
    public const PARTIALLY_RECEIVED = 'payment.bank_transfer.partially_received';
    public const UNDERPAID = 'payment.bank_transfer.underpaid';
    public const OVERPAID = 'payment.bank_transfer.overpaid';
    public const LATE_ARRIVAL = 'payment.bank_transfer.late_arrival';

    public const ALL = [self::PARTIALLY_RECEIVED, self::UNDERPAID, self::OVERPAID, self::LATE_ARRIVAL];

    private function __construct()
    {
    }
}
