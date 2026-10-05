<?php

declare(strict_types=1);

/*
 * An end user's balances, KYC level and remaining limits. Balances are
 * minor-unit digit strings ("12550" is 125.50 TRY); keep them as strings or
 * integers, never floats. Needs `wallet:enduser:read` and `wallet:limit:read`.
 */

require_once __DIR__ . '/../_common/bootstrap.php';

$endUserId = sample_required_env('LUNIXI_WALLET_END_USER_ID');
$endUsers = sample_client()->wallet()->endUsers;

sample_print([
    'balances' => $endUsers->getBalances($endUserId),
    'kycLevel' => $endUsers->getKycLevel($endUserId),
    'limits' => $endUsers->getLimits($endUserId, ['operationType' => 'W2W', 'currency' => 'TRY']),
]);
