<?php

declare(strict_types=1);

/*
 * Creates a wallet end user (and their default wallet) for one of your
 * customers, then reads them back by your own customer id.
 *
 * The Idempotency-Key is derived from your customer id: running this again for
 * the same customer returns the same end user instead of creating a second one.
 * Needs the `wallet:enduser:manage` scope and the WALLET_SERVICE product.
 */

use Lunixi\Sdk\Wallet\WalletAccountType;

require_once __DIR__ . '/../_common/bootstrap.php';

$programId = sample_required_env('LUNIXI_WALLET_PROGRAM_ID');
$customerId = (string) sample_env('LUNIXI_WALLET_EXTERNAL_CUSTOMER_ID', 'cust_demo_001');

$endUsers = sample_client()->wallet()->endUsers;

$endUser = $endUsers->create([
    'programId' => $programId,
    'accountType' => WalletAccountType::PERSONAL,
    'phone' => (string) sample_env('LUNIXI_WALLET_PHONE', '+905350000000'),
    'externalCustomerId' => $customerId,
    'displayName' => (string) sample_env('LUNIXI_BUYER_NAME', 'Ada'),
    'defaultCurrency' => 'TRY',
], 'wallet-enduser-' . $customerId);

sample_print($endUser);
sample_print($endUsers->getByExternalId($customerId, $programId));
