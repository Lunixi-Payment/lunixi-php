<?php

declare(strict_types=1);

/*
 * Sends a link by e-mail. Without recipientEmail the link's own recipient is
 * used.
 *
 * The Idempotency-Key is required: reuse the same key when you retry, so the
 * buyer does not receive the message twice.
 *
 * A send can be accepted and still fail: check delivery.state and
 * delivery.failureReason. With a TEST key only your organisation's own verified
 * member addresses receive messages (`test_mode_recipient_not_allowed`
 * otherwise).
 */

use Lunixi\Sdk\Payment\PaymentLinkClient;

require_once __DIR__ . '/../_common/bootstrap.php';

$linkId = sample_required_env('LUNIXI_PAYMENT_LINK_ID');

$result = sample_client()->paymentLinks()->send(
    $linkId,
    PaymentLinkClient::CHANNEL_EMAIL,
    'plink-send-' . $linkId . '-' . sample_env('LUNIXI_SEND_ROUND', '1'),
    ['recipientEmail' => sample_env('LUNIXI_RECIPIENT_EMAIL'), 'locale' => 'tr']
);

sample_print($result);
