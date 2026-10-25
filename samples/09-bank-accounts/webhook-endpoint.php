<?php

declare(strict_types=1);

/*
 * Webhook endpoint for `bank_account.transaction.received` (subscribe a
 * BANK_CONNECT endpoint to `bank_account.*`). The event is verified first and
 * deduplicated by its id: delivery is at-least-once and the id is the same on
 * every redelivery of the same transaction.
 */

use Lunixi\Sdk\BankConnect\BankAccountTransactionReceived;
use Lunixi\Sdk\BankConnect\BankConnectEvents;
use Lunixi\Sdk\Exception\WebhookVerificationException;

require_once __DIR__ . '/../_common/bootstrap.php';

$rawBody = file_get_contents('php://input');
$rawBody = $rawBody === false ? '' : $rawBody;

try {
    $event = sample_client()->webhooks()->verify($rawBody, getallheaders() ?: [], sample_required_env('LUNIXI_WEBHOOK_SECRET'));
} catch (WebhookVerificationException $e) {
    http_response_code(400);
    echo 'invalid signature';
    return;
}

if ($event->type() === BankConnectEvents::TRANSACTION_RECEIVED) {
    $transaction = BankAccountTransactionReceived::fromEvent($event);
    // Store it keyed by $event->id() (or $transaction->transactionId()), then match
    // $transaction->description() against your open orders. Keep TEST events
    // ($transaction->environment() === 'TEST') away from live books.
    error_log(sprintf(
        'bank %s %s %d %s',
        $transaction->accountId(),
        $transaction->direction(),
        $transaction->amountMinor(),
        $transaction->currency()
    ));
}

http_response_code(200);
echo 'ok';
