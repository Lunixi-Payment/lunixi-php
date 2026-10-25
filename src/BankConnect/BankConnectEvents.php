<?php

declare(strict_types=1);

namespace Lunixi\Sdk\BankConnect;

/**
 * Bank-account webhook event types (product BANK_CONNECT), delivered in the
 * same signed envelope as every Lunixi webhook and verified with
 * WebhookVerifier. Read the payload with BankAccountTransactionReceived.
 */
final class BankConnectEvents
{
    /**
     * A new transaction (CREDIT or DEBIT) was read from one of your connected
     * accounts. Sent once per transaction, for transactions first seen by a
     * regular read after the connection became active; the history loaded
     * when an account is first connected does not send it. The event id is
     * the same on every redelivery: dedupe on it.
     */
    public const TRANSACTION_RECEIVED = 'bank_account.transaction.received';

    /** Subscription pattern covering every bank-account event. */
    public const ALL_EVENTS = 'bank_account.*';

    public const ALL = [
        self::TRANSACTION_RECEIVED,
    ];

    private function __construct()
    {
    }
}
