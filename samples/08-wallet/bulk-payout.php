<?php

declare(strict_types=1);

/*
 * Bulk payout, four-eyes: the maker creates and submits the batch, a checker
 * approves it and pays it out.
 *
 * The maker key (LUNIXI_KEY_ID) needs `wallet:bulk:create` to create and
 * `wallet:bulk:approve` to submit. Approving must be done by a DIFFERENT key
 * (also `wallet:bulk:approve`): set LUNIXI_WALLET_APPROVER_KEY_ID and
 * LUNIXI_WALLET_APPROVER_PRIVATE_KEY_PATH to run the checker side here too.
 * Item amounts are minor-unit strings.
 */

use Lunixi\Sdk\LunixiClient;
use Lunixi\Sdk\Wallet\WalletBulkTargetType;

require_once __DIR__ . '/../_common/bootstrap.php';

$payrollRef = (string) sample_env('LUNIXI_WALLET_PAYROLL_REF', 'PAYROLL-' . gmdate('Y-m'));
$maker = sample_client()->wallet()->bulkPayouts;

// Maker: create the batch and send it for approval.
$created = $maker->create([
    'programId' => sample_required_env('LUNIXI_WALLET_PROGRAM_ID'),
    'sourceWalletAccountId' => sample_required_env('LUNIXI_WALLET_ACCOUNT_ID'),
    'currency' => 'TRY',
    'reason' => $payrollRef,
    'items' => [
        ['targetType' => WalletBulkTargetType::WALLET, 'targetRef' => sample_required_env('LUNIXI_WALLET_DESTINATION_WALLET_NO'), 'amount' => '150000'],
        ['targetType' => WalletBulkTargetType::IBAN, 'targetRef' => 'TR330006100519786457841326', 'amount' => '275050', 'beneficiaryName' => 'Ada Yilmaz'],
    ],
], 'wallet-bulk-' . $payrollRef);
$batchId = (string) $created['batch']['id'];
sample_print($created);
sample_print($maker->submit($batchId));

$approverKeyId = sample_env('LUNIXI_WALLET_APPROVER_KEY_ID');
$approverKeyPath = sample_env('LUNIXI_WALLET_APPROVER_PRIVATE_KEY_PATH');
if ($approverKeyId === null || $approverKeyPath === null) {
    echo 'Batch submitted. A different key approves and processes it.' . PHP_EOL;
    return;
}
$approverKey = @file_get_contents($approverKeyPath);
if ($approverKey === false || trim($approverKey) === '') {
    throw new RuntimeException('LUNIXI_WALLET_APPROVER_PRIVATE_KEY_PATH could not be read.');
}

// Checker: a different key approves, then process() pays the batch out.
$checker = LunixiClient::create([
    'baseUrl' => sample_env('LUNIXI_BASE_URL', 'https://api-gateway.lunixi.com'),
    'environment' => sample_env('LUNIXI_ENVIRONMENT', 'TEST'),
    'keyId' => $approverKeyId,
    'privateKey' => $approverKey,
])->wallet()->bulkPayouts;

sample_print($checker->approve($batchId));
sample_print($checker->process($batchId));
