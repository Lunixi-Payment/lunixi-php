<?php

declare(strict_types=1);

use Lunixi\Sdk\Payment\CardDetails;
use Lunixi\Sdk\Payment\DirectPaymentRequest;

require_once __DIR__ . '/../_common/bootstrap.php';

$request = (new DirectPaymentRequest(
    10050,
    'TRY',
    'ORD-STORED-CARD-' . date('YmdHis'),
    new CardDetails([
        'cardUserKey' => sample_env('LUNIXI_CARD_USER_KEY', 'cust_demo_001'),
        'cardToken' => sample_required_env('LUNIXI_STORED_CARD_TOKEN'),
        // Some acquirers require CVC for customer-present stored-card payments.
        'cvcNumber' => sample_env('LUNIXI_CARD_CVC', '123'),
    ]),
    sample_buyer(),
    sample_billing_address(),
    sample_basket_items(10050)
))
    ->withPaymentChannel('WEB')
    ->withPaymentGroup('PRODUCT')
    ->withDescription('Demo stored-card payment')
    ->withCustomerId(sample_env('LUNIXI_CUSTOMER_ID', 'cust_demo_001'));

sample_print(sample_client()->payments()->chargeCard2d($request, sample_idempotency_key('stored-card-2d')));
