<?php

declare(strict_types=1);

require_once __DIR__ . '/../_common/bootstrap.php';

$paymentId = sample_required_env('LUNIXI_PAYMENT_ID');

sample_print(
    sample_client()->payments()->capture($paymentId, null, sample_idempotency_key('capture'))
);
