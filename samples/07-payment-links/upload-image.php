<?php

declare(strict_types=1);

/*
 * Uploads a link image and shows it on a link.
 *
 * uploadImage() asks the gateway for a presigned upload URL, PUTs the file
 * straight to the storage host (with only the upload headers: never your access
 * token or signature) and confirms it. Media then checks the stored bytes: PNG,
 * JPEG or WebP (never SVG), at most 2 MiB and 4096×4096 px.
 */

use Lunixi\Sdk\Payment\PaymentLinkClient;

require_once __DIR__ . '/../_common/bootstrap.php';

$contentTypes = [
    'png' => PaymentLinkClient::IMAGE_PNG,
    'jpg' => PaymentLinkClient::IMAGE_JPEG,
    'jpeg' => PaymentLinkClient::IMAGE_JPEG,
    'webp' => PaymentLinkClient::IMAGE_WEBP,
];

$file = sample_required_env('LUNIXI_IMAGE_FILE');
$contentType = $contentTypes[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? null;
if ($contentType === null) {
    throw new RuntimeException('LUNIXI_IMAGE_FILE must be a .png, .jpg, .jpeg or .webp file.');
}
$bytes = file_get_contents($file);
if ($bytes === false) {
    throw new RuntimeException("Cannot read {$file}.");
}

$links = sample_client()->paymentLinks();
$image = $links->uploadImage(basename($file), $contentType, $bytes);

$linkId = sample_required_env('LUNIXI_PAYMENT_LINK_ID');
$current = $links->get($linkId);
$updated = $links->update($linkId, $current->rowVersion(), ['imageAssetId' => $image['assetId']]);
sample_print(['assetId' => $image['assetId'], 'url' => $image['url'], 'linkVersion' => $updated->version()]);
