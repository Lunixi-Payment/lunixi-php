<?php

declare(strict_types=1);

/*
 * Tops up a wallet account by card. Render the returned checkoutFormContent
 * (or follow redirectUrl / threeDsHtml); the wallet is credited when the card
 * payment completes, announced by the `wallet.topup.completed` webhook. Do not
 * credit anything on your side from this response. Needs
 * `wallet:operation:intervene`.
 */

require_once __DIR__ . '/../_common/bootstrap.php';

$topupRef = (string) sample_env('LUNIXI_WALLET_TOPUP_REF', 'TOP-' . gmdate('Ymd') . '-0001');

$topup = sample_client()->wallet()->topups->startCard([
    'creditWalletAccountId' => sample_required_env('LUNIXI_WALLET_ACCOUNT_ID'),
    'amount' => '25000', // 250.00 TRY
    'currency' => 'TRY',
    'returnUrl' => (string) sample_env('LUNIXI_CALLBACK_URL', 'https://merchant.example.com/wallet/topup/return'),
], 'wallet-topup-' . $topupRef);

sample_print($topup);
