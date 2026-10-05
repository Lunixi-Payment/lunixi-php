<?php

declare(strict_types=1);

/*
 * Wallet-to-wallet transfer in two steps: quote (shows the fee), initiate
 * (reserves the limit and may send an OTP to the end user), complete (commits).
 *
 * Every step names the end user whose account the money leaves; an account of
 * another end user is 404 WALLET_NOT_FOUND. Each mutating step has its own
 * Idempotency-Key derived from your transfer reference, so a retry after a
 * timeout repeats the same step instead of moving the money twice. Needs the
 * `wallet:operation:intervene` scope.
 */

use Lunixi\Sdk\Wallet\WalletOtpChannel;

require_once __DIR__ . '/../_common/bootstrap.php';

$transferRef = (string) sample_env('LUNIXI_WALLET_TRANSFER_REF', 'TRF-' . gmdate('Ymd') . '-0001');
$endUserId = sample_required_env('LUNIXI_WALLET_END_USER_ID');

$transfer = [
    'endUserId' => $endUserId,
    'sourceWalletAccountId' => sample_required_env('LUNIXI_WALLET_ACCOUNT_ID'),
    'destinationWalletNo' => sample_required_env('LUNIXI_WALLET_DESTINATION_WALLET_NO'),
    'amount' => '12550', // 125.50 TRY in minor units
    'currency' => 'TRY',
];

$transfers = sample_client()->wallet()->transfers;

$quote = $transfers->quoteW2W($transfer);
sample_print(['quote' => $quote]);

$started = $transfers->initiateW2W($transfer + [
    'scheduleVersionId' => $quote['scheduleVersionId'] ?? null, // holds the quoted fee
    'otpChannel' => WalletOtpChannel::SMS,
], 'w2w-' . $transferRef);

$completion = [
    'endUserId' => $endUserId,
    'integrityHash' => $started['integrityHash'],
    'nonce' => $started['nonce'],
];
if (($started['otpRequired'] ?? false) === true) {
    echo 'OTP sent to the end user. Enter it: ';
    $completion['otpCode'] = trim((string) fgets(STDIN));
}

sample_print($transfers->completeW2W($started['operationId'], $completion, 'w2w-' . $transferRef . '-complete'));
