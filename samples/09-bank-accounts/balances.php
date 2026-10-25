<?php

declare(strict_types=1);

/*
 * The latest balance of each connected account. Amounts are integers in minor
 * units (125050 = 1,250.50 TRY); `availableMinor` and `blockedMinor` are null
 * when the bank does not report them. Needs `bank-connect:transactions:view`.
 */

require_once __DIR__ . '/../_common/bootstrap.php';

$filters = ['currency' => 'TRY'];
$accountId = sample_env('LUNIXI_BANK_ACCOUNT_ID');
if ($accountId !== null) {
    $filters['accountId'] = [$accountId];
}

foreach (sample_client()->bankConnect()->iterateBalances($filters) as $balance) {
    sample_print([
        'accountId' => $balance->accountId(),
        'balanceMinor' => $balance->balanceMinor(),
        'availableMinor' => $balance->availableMinor(),
        'asOf' => $balance->asOf(),
    ]);
}
