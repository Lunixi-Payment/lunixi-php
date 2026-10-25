<?php

declare(strict_types=1);

/*
 * Incoming transfers of the last 7 days, matched to your orders by the payment
 * reference the payer wrote into the transfer description. The list is ordered
 * by when Lunixi recorded each transaction (newest first), so a transfer the
 * bank reports late is never skipped while paging. A window may be at most 92
 * days. Needs `bank-connect:transactions:view`.
 */

use Lunixi\Sdk\BankConnect\BankTransactionDirection;
use Lunixi\Sdk\BankConnect\BankTransactionType;

require_once __DIR__ . '/../_common/bootstrap.php';

$transactions = sample_client()->bankConnect()->iterateTransactions([
    'bookedFrom' => new DateTimeImmutable('-7 days'),
    'direction' => BankTransactionDirection::CREDIT,
    'canonicalType' => [BankTransactionType::TRANSFER_IN],
    'limit' => 100,
]);

$referencePattern = '/\bLNX-[A-Z0-9]{4,12}\b/';
foreach ($transactions as $transaction) {
    $reference = preg_match($referencePattern, (string) $transaction->description(), $match) === 1 ? $match[0] : null;
    sample_print([
        'transactionId' => $transaction->transactionId(),
        'amountMinor' => $transaction->amountMinor(),
        'bookedAt' => $transaction->bookedAt(),
        'from' => $transaction->counterpartyNameMasked(),
        'orderReference' => $reference,
    ]);
}
