<?php

declare(strict_types=1);

/*
 * Your bank connections and the accounts read from them, with how current each
 * account's data is. Connecting a bank happens in the Lunixi dashboard; the API
 * reads. Needs `bank-connect:view`. A TEST key sees only test-environment
 * accounts.
 */

use Lunixi\Sdk\BankConnect\BankAccountStatus;

require_once __DIR__ . '/../_common/bootstrap.php';

$bankConnect = sample_client()->bankConnect();

foreach ($bankConnect->iterateConnections() as $connection) {
    sample_print([
        'connectionId' => $connection->connectionId(),
        'bank' => $connection->bank()->name(),
        'status' => $connection->status(),
        'lastSuccessfulSyncAt' => $connection->lastSuccessfulSyncAt(),
        'accountCount' => $connection->accountCount(),
    ]);
}

foreach ($bankConnect->iterateAccounts(['status' => BankAccountStatus::ACTIVE]) as $account) {
    sample_print([
        'accountId' => $account->accountId(),
        'iban' => $account->ibanMasked(),
        'currency' => $account->currency(),
        'lastSuccessfulSyncAt' => $account->freshness()->lastSuccessfulSyncAt(),
        'nextSyncAvailableAt' => $account->freshness()->nextSyncAvailableAt(),
    ]);
}
