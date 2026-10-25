<?php

declare(strict_types=1);

use Lunixi\Sdk\Payment\CardDetails;
use Lunixi\Sdk\Payment\DirectMethodPaymentRequest;

require_once __DIR__ . '/../_common/bootstrap.php';

// A stored card is charged through directMethodPayment(): cardUserKey +
// cardToken in the card block. Without withForce3D() it is charged without
// 3D Secure; the bank may still ask for it (the response then carries a
// REDIRECT action).
$amount = 10050;
$request = (new DirectMethodPaymentRequest($amount, 'TRY', 'ORD-STORED-CARD-' . date('YmdHis')))
    ->withCard(new CardDetails([
        'cardUserKey' => sample_env('LUNIXI_CARD_USER_KEY', 'cust_demo_001'),
        'cardToken' => sample_required_env('LUNIXI_STORED_CARD_TOKEN'),
        // Some acquirers require CVC for customer-present stored-card payments.
        'cvcNumber' => sample_env('LUNIXI_CARD_CVC', '123'),
    ]))
    ->withBuyer(sample_buyer())
    ->withBillingAddress(sample_billing_address())
    ->withBasketItems(sample_basket_items($amount))
    ->withPaymentChannel('WEB')
    ->withPaymentGroup('PRODUCT')
    ->withDescription('Demo stored-card payment')
    ->withCustomerId(sample_env('LUNIXI_CUSTOMER_ID', 'cust_demo_001'));

sample_print(sample_client()->payments()->directMethodPayment($request, sample_idempotency_key('stored-card'))->response());
