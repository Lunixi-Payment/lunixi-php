<?php

declare(strict_types=1);

/*
 * The end user pays you from their wallet: request the payment (by wallet
 * number or phone), then approve it on the buyer's behalf with the
 * integrityHash and nonce from the request (and the OTP when one is required).
 *
 * The creditor is the wallet terminal bound to your signing key; do not send a
 * businessAccountId. `buyerEndUserId` must own the operation, otherwise the
 * answer is 404 WALLET_NOT_FOUND. Needs `wallet:operation:intervene`.
 */

require_once __DIR__ . '/../_common/bootstrap.php';

$orderRef = (string) sample_env('LUNIXI_WALLET_ORDER_REF', 'ORD-' . gmdate('Ymd') . '-0001');
$payments = sample_client()->wallet()->payments;

$requested = $payments->request([
    'amount' => '9900', // 99.00 TRY
    'currency' => 'TRY',
    'paymentPurpose' => 'ORDER',
    'buyerWalletNo' => sample_required_env('LUNIXI_WALLET_BUYER_WALLET_NO'),
    'orderRef' => $orderRef,
], 'wallet-pay-' . $orderRef);
sample_print(['requested' => $requested]);

$approval = [
    'buyerEndUserId' => sample_required_env('LUNIXI_WALLET_END_USER_ID'),
    'integrityHash' => $requested['integrityHash'],
    'nonce' => $requested['nonce'],
];
if (($requested['otpRequired'] ?? false) === true) {
    echo 'OTP sent to the buyer. Enter it: ';
    $approval['otpCode'] = trim((string) fgets(STDIN));
}

// The approve path takes the id of the charge request.
sample_print($payments->approve((string) $requested['requestId'], $approval, 'wallet-pay-' . $orderRef . '-approve'));
