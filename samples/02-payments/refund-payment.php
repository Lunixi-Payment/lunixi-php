<?php

declare(strict_types=1);

require_once __DIR__ . '/../_common/bootstrap.php';

$paymentId = sample_required_env('LUNIXI_PAYMENT_ID');
$amount = sample_env('LUNIXI_REFUND_AMOUNT');

sample_print(
    sample_client()->payments()->refund(
        $paymentId,
        $amount !== null ? (int) $amount : null,
        sample_env('LUNIXI_REFUND_REASON', 'merchant requested refund'),
        sample_idempotency_key('refund')
    )
);
