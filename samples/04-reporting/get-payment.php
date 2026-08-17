<?php

declare(strict_types=1);

require_once __DIR__ . '/../_common/bootstrap.php';

$payment = sample_client()->payments()->get(sample_required_env('LUNIXI_PAYMENT_ID'));

sample_print([
    'id' => $payment->id(),
    'status' => $payment->status(),
    'amount' => $payment->amount(),
    'currency' => $payment->currency(),
    'awaiting3D' => $payment->awaiting3D(),
    'raw' => $payment->raw(),
]);
