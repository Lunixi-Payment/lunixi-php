<?php

declare(strict_types=1);

use Lunixi\Sdk\Payment\CardDetails;
use Lunixi\Sdk\Payment\DirectPaymentRequest;

require_once __DIR__ . '/../_common/bootstrap.php';

// PCI warning: raw PAN enters your server in this flow. Prefer hosted checkout
// unless your environment is explicitly certified for direct card processing.
$request = (new DirectPaymentRequest(
    10050,
    'TRY',
    'ORD-DIRECT-2D-' . date('YmdHis'),
    new CardDetails([
        'cardHolderName' => 'Ada Yilmaz',
        'cardNumber' => '5400000000000004',
        'expireMonth' => '12',
        'expireYear' => '28',
        'cvcNumber' => '123',
    ]),
    sample_buyer(),
    sample_billing_address(),
    sample_basket_items(10050)
))
    ->withPaymentChannel('WEB')
    ->withPaymentGroup('PRODUCT')
    ->withDescription('Demo direct 2D payment')
    ->withCustomerId(sample_env('LUNIXI_CUSTOMER_ID', 'cust_demo_001'));

sample_print(sample_client()->payments()->chargeCard2d($request, sample_idempotency_key('direct-2d')));
