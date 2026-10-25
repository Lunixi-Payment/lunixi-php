<?php

declare(strict_types=1);

/*
 * An order whose premium you collected outside Lunixi (your own POS, a bank
 * transfer …): record the payment, then request the policy. `paidAmount` and
 * `paidCurrency` must equal the order's premium. The policy result arrives by
 * the `insurance.policy.issued` or `insurance.policy.failed` webhook.
 *
 * LUNIXI_INSURANCE_ORDER_ID is an order created with createOrder() and not
 * bound to a Lunixi payment; LUNIXI_EXTERNAL_PAYMENT_ID is your own payment id.
 */

use Lunixi\Sdk\Exception\ApiException;

require_once __DIR__ . '/../_common/bootstrap.php';

$insurance = sample_client()->insurance();
$orderId = sample_required_env('LUNIXI_INSURANCE_ORDER_ID');
$externalPaymentId = sample_required_env('LUNIXI_EXTERNAL_PAYMENT_ID');

try {
    $order = $insurance->getOrder($orderId);
    $insurance->confirmPayment($orderId, [
        'externalPaymentId' => $externalPaymentId,
        'paidAmount' => $order->premiumAmount(),
        'paidCurrency' => $order->currency(),
        'paidAt' => new DateTimeImmutable(),
    ], 'ins-confirm-' . $externalPaymentId);

    $requested = $insurance->issuePolicy($orderId, 'ins-issue-' . $orderId);
    sample_print([
        'orderId' => $orderId,
        'collectionStatus' => $requested->collectionStatus(),
        'policyStatus' => $requested->policyStatus(),
    ]);
} catch (ApiException $e) {
    // e.g. 409 insurance.order.state_conflict (details.reason lunixi_payment) or
    // 422 insurance.validation.invalid (premium_amount_mismatch).
    sample_print(['status' => $e->getStatusCode(), 'code' => $e->getErrorCode(), 'details' => ($e->getResponseBody() ?? [])['details'] ?? null]);
}
