<?php

declare(strict_types=1);

/*
 * Payments received through a link, and its failed attempts.
 *
 * Fulfil orders from the `payment.captured` webhook (its payload carries
 * paymentLinkId and paymentLinkCode), not from these lists: they are for
 * reporting and reconciliation.
 */

use Lunixi\Sdk\Payment\PaymentLinkAttemptState;

require_once __DIR__ . '/../_common/bootstrap.php';

$links = sample_client()->paymentLinks();
$linkId = sample_required_env('LUNIXI_PAYMENT_LINK_ID');

$payments = [];
foreach ($links->iteratePayments($linkId, ['limit' => 100]) as $payment) {
    $payments[] = [
        'paymentId' => $payment['paymentId'] ?? null,
        'amountMinor' => $payment['amountMinor'] ?? null,
        'refundedAmountMinor' => $payment['refundedAmountMinor'] ?? null,
        'receiptNumber' => $payment['receiptNumber'] ?? null,
    ];
}
sample_print($payments);

$failed = $links->listAttempts($linkId, [
    'state' => [PaymentLinkAttemptState::FAILED, PaymentLinkAttemptState::EXPIRED],
    'limit' => 20,
]);
sample_print(array_map(
    static fn (array $attempt): array => [
        'id' => $attempt['id'] ?? null,
        'state' => $attempt['state'] ?? null,
        'failureCode' => $attempt['failureCode'] ?? null,
    ],
    $failed->items()
));
