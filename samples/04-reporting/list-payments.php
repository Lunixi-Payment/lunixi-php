<?php

declare(strict_types=1);

require_once __DIR__ . '/../_common/bootstrap.php';

$list = sample_client()->payments()->list([
    'page' => (int) sample_env('LUNIXI_PAGE', '1'),
    'limit' => (int) sample_env('LUNIXI_LIMIT', '20'),
    'status' => sample_env('LUNIXI_PAYMENT_STATUS', ''),
    'customerId' => sample_env('LUNIXI_CUSTOMER_ID', ''),
]);

sample_print([
    'total' => $list->total(),
    'page' => $list->page(),
    'limit' => $list->limit(),
    'items' => array_map(static fn ($payment): array => $payment->raw(), $list->items()),
]);
