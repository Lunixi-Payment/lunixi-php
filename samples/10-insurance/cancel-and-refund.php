<?php

declare(strict_types=1);

/*
 * Cancels the policies of an order, then refunds the premium. Cancellation is
 * asynchronous (CANCEL_PENDING, confirmed by `insurance.policy.cancelled`) and
 * does not refund anything by itself. With a Lunixi payment the premium is
 * refunded from that payment (`refundStatus` PENDING, then
 * `insurance.refund.completed`); for a premium collected outside Lunixi you
 * report the refund you made with `externalRefund`.
 */

use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Insurance\InsurancePaymentMode;
use Lunixi\Sdk\Insurance\InsurancePolicyStatus;

require_once __DIR__ . '/../_common/bootstrap.php';

$insurance = sample_client()->insurance();
$orderId = sample_required_env('LUNIXI_INSURANCE_ORDER_ID');

try {
    $cancelled = $insurance->cancelOrder($orderId, 'ins-cancel-' . $orderId);
    sample_print(['orderId' => $orderId, 'policyStatus' => $cancelled->policyStatus()]);

    if ($cancelled->policyStatus() !== InsurancePolicyStatus::CANCELLED) {
        // Run again after the insurance.policy.cancelled webhook.
        return;
    }

    $options = [];
    if ($cancelled->paymentMode() === InsurancePaymentMode::EXTERNAL_PAYMENT) {
        $options['externalRefund'] = [
            'externalRefundId' => sample_required_env('LUNIXI_EXTERNAL_REFUND_ID'),
            'refundedAt' => sample_env('LUNIXI_EXTERNAL_REFUNDED_AT') ?? new DateTimeImmutable(),
        ];
    }
    $refunded = $insurance->refundPremium($orderId, $options, 'ins-refund-' . $orderId);
    sample_print(['orderId' => $orderId, 'refundStatus' => $refunded->refundStatus(), 'status' => $refunded->status()]);
} catch (ApiException $e) {
    // e.g. 409 insurance.order.state_conflict with details.reason policy_not_issued.
    sample_print(['status' => $e->getStatusCode(), 'code' => $e->getErrorCode(), 'details' => ($e->getResponseBody() ?? [])['details'] ?? null]);
}
