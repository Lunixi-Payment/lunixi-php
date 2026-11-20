<?php

declare(strict_types=1);

/*
 * Tops up a wallet account by card. Render the returned checkoutFormContent
 * (or follow redirectUrl / threeDsHtml); the wallet is credited when the card
 * payment completes, announced by the `wallet.topup.completed` webhook. Do not
 * credit anything on your side from this response. Needs
 * `wallet:operation:intervene`.
 *
 * The card payment provider requires the buyer and the billing address (plain
 * arrays; `buyer.ip` is the user's browser IP). A missing field is answered
 * with 400 `payment.order.field_required`. `shippingAddress` is optional.
 */

require_once __DIR__ . '/../_common/bootstrap.php';

$topupRef = (string) sample_env('LUNIXI_WALLET_TOPUP_REF', 'TOP-' . gmdate('Ymd') . '-0001');

$topup = sample_client()->wallet()->topups->startCard([
    'creditWalletAccountId' => sample_required_env('LUNIXI_WALLET_ACCOUNT_ID'),
    'amount' => '25000', // 250.00 TRY
    'currency' => 'TRY',
    'returnUrl' => (string) sample_env('LUNIXI_CALLBACK_URL', 'https://merchant.example.com/wallet/topup/return'),
    // Buyer / Address validate the required fields; toArray() gives the arrays the route takes.
    'buyer' => sample_buyer()->toArray(),
    'billingAddress' => sample_billing_address()->toArray(),
], 'wallet-topup-' . $topupRef);

sample_print($topup);
