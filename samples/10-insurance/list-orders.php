<?php

declare(strict_types=1);

/*
 * Your insurance orders, newest first, with their status axes. Filter by
 * `paymentIntentId` or `merchantOrderId` to find the orders of one checkout.
 */

use Lunixi\Sdk\Insurance\InsuranceOrderPolicy;

require_once __DIR__ . '/../_common/bootstrap.php';

$filters = ['limit' => 50];
$merchantOrderId = sample_env('LUNIXI_MERCHANT_ORDER_ID');
if ($merchantOrderId !== null) {
    $filters['merchantOrderId'] = $merchantOrderId;
}

foreach (sample_client()->insurance()->iterateOrders($filters) as $order) {
    sample_print([
        'orderId' => $order->orderId(),
        'merchantOrderId' => $order->merchantOrderId(),
        'status' => $order->status(),
        'collectionStatus' => $order->collectionStatus(),
        'policyStatus' => $order->policyStatus(),
        'refundStatus' => $order->refundStatus(),
        'premiumAmount' => $order->premiumAmount(),
        'policies' => array_map(static fn (InsuranceOrderPolicy $policy): array => [
            'policyId' => $policy->policyId(),
            'policyNumber' => $policy->policyNumber(),
            'policyStatus' => $policy->policyStatus(),
        ], $order->policies()),
    ]);
}
