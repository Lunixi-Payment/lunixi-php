<?php

declare(strict_types=1);

/*
 * Saves the link's QR code. The QR encodes the link URL
 * (https://pay.lunixi.com/<code>), so a printed code keeps working as long as
 * the link does.
 */

use Lunixi\Sdk\Payment\PaymentLinkClient;

require_once __DIR__ . '/../_common/bootstrap.php';

$linkId = sample_required_env('LUNIXI_PAYMENT_LINK_ID');
$format = (string) sample_env('LUNIXI_QR_FORMAT', PaymentLinkClient::QR_PNG);

$qr = sample_client()->paymentLinks()->qr($linkId, $format, 1024);

$file = (string) sample_env('LUNIXI_QR_OUT', 'payment-link-' . $linkId . '.' . $qr->extension());
if (file_put_contents($file, $qr->bytes()) === false) {
    throw new RuntimeException('Could not write ' . $file);
}
echo $qr->contentType() . ', ' . strlen($qr->bytes()) . ' bytes -> ' . $file . PHP_EOL;
