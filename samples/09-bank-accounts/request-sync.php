<?php

declare(strict_types=1);

/*
 * Asks for a fresh read of one account. It never reads earlier than the bank's
 * minimum interval: the answer is QUEUED (reading now) or SCHEDULED (at
 * `scheduledAt`). New transactions then arrive by the
 * `bank_account.transaction.received` webhook. Needs `bank-connect:view`.
 */

use Lunixi\Sdk\BankConnect\BankConnectClient;
use Lunixi\Sdk\Exception\ApiException;

require_once __DIR__ . '/../_common/bootstrap.php';

try {
    $sync = sample_client()->bankConnect()->requestSync(sample_required_env('LUNIXI_BANK_ACCOUNT_ID'));
    sample_print([
        'syncStatus' => $sync->syncStatus(),
        'scheduledAt' => $sync->scheduledAt(),
        'lastSuccessfulSyncAt' => $sync->lastSuccessfulSyncAt(),
    ]);
} catch (ApiException $error) {
    $retryAfter = BankConnectClient::retryAfterSeconds($error);
    if ($retryAfter !== null) {
        sample_print(['rateLimited' => true, 'retryAfterSeconds' => $retryAfter]);
        return;
    }
    // e.g. 409 bank_connect.connection.not_active: the connection is not ACTIVE or DEGRADED.
    sample_print(['status' => $error->getStatusCode(), 'code' => $error->getErrorCode()]);
}
