<?php

declare(strict_types=1);

/*
 * A multi-use itemized link: the buyer picks quantities of each ticket type.
 *
 * `itemKey` is the permanent identity of a line (stock is counted on it) and
 * cannot be changed later. `capacity` caps the units sold across all payments;
 * when it is reached the link stays ACTIVE with availability.soldOut = true.
 */

use Lunixi\Sdk\Payment\CreatePaymentLinkRequest;
use Lunixi\Sdk\Payment\PaymentLinkCustomField;
use Lunixi\Sdk\Payment\PaymentLinkItem;
use Lunixi\Sdk\Payment\PaymentLinkUsage;

require_once __DIR__ . '/../_common/bootstrap.php';

// The concert is 30 days from today at 20:00 Istanbul time; sales close two
// hours before it. `expiresAt` must be in the future.
$eventAt = (new DateTimeImmutable('today 20:00', new DateTimeZone('Europe/Istanbul')))->modify('+30 days');

$request = CreatePaymentLinkRequest::itemized(PaymentLinkUsage::MULTI_USE, 'TRY', 'Yıl sonu konseri', [
    (new PaymentLinkItem('Standart bilet', 45000))->withItemKey('standard')->withQuantityRange(0, 6)->withCapacity(400),
    (new PaymentLinkItem('Balkon bilet', 30000))->withItemKey('balcony')->withQuantityRange(0, 6)->withCapacity(150),
])
    ->withEventAt($eventAt)
    ->withExpiresAt($eventAt->modify('-2 hours'))
    ->withButtonLabelKey(CreatePaymentLinkRequest::BUTTON_BUY)
    ->withTermsAcceptanceRequired()
    ->addCustomField(
        (new PaymentLinkCustomField('seat_note', 'Koltuk tercihi', PaymentLinkCustomField::TYPE_TEXT))->withMaxLength(60)
    )
    ->withTags(['konser', 'etkinlik']);

$link = sample_client()->paymentLinks()->create($request, sample_idempotency_key('plink-tickets'));

sample_print(['id' => $link->id(), 'url' => $link->url(), 'items' => $link->get('items'), 'availability' => $link->availability()]);
