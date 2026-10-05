<?php

declare(strict_types=1);

/*
 * Creates a dynamic merchant QR code for one order and saves it as a PNG.
 * Dynamic codes carry the amount; static ones carry none. Needs
 * `wallet:qr:manage`.
 */

use Lunixi\Sdk\Wallet\WalletQrImageFormat;
use Lunixi\Sdk\Wallet\WalletQrType;

require_once __DIR__ . '/../_common/bootstrap.php';

$orderRef = (string) sample_env('LUNIXI_WALLET_ORDER_REF', 'ORD-' . gmdate('Ymd') . '-0001');
$qr = sample_client()->wallet()->qr;

$code = $qr->create([
    'qrType' => WalletQrType::MERCHANT_DYNAMIC,
    'currency' => 'TRY',
    'amount' => '15000', // 150.00 TRY
    'programId' => sample_required_env('LUNIXI_WALLET_PROGRAM_ID'),
    'expiresInSeconds' => 900,
    'metadata' => ['orderRef' => $orderRef],
], 'wallet-qr-' . $orderRef);
sample_print($code);

$image = $qr->image((string) $code['id'], ['format' => WalletQrImageFormat::PNG, 'scale' => 8]);
$file = (string) sample_env('LUNIXI_QR_OUT', 'wallet-qr-' . $orderRef . '.' . $image->extension());
if (file_put_contents($file, $image->bytes()) === false) {
    throw new RuntimeException('Could not write ' . $file);
}
echo $image->contentType() . ', ' . strlen($image->bytes()) . ' bytes -> ' . $file . PHP_EOL;
