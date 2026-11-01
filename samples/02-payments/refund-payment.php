<?php

declare(strict_types=1);

require_once __DIR__ . '/../_common/bootstrap.php';

$paymentId = sample_required_env('LUNIXI_PAYMENT_ID');
$amount = sample_env('LUNIXI_REFUND_AMOUNT');

$payments = sample_client()->payments();
$reason = sample_env('LUNIXI_REFUND_REASON', 'merchant requested refund');
$key = sample_idempotency_key('refund');

sample_print($amount === null
    ? $payments->refundRemaining($paymentId, $reason, $key)
    : $payments->refund($paymentId, $amount, $reason, $key)
);
