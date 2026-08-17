<?php

declare(strict_types=1);

use Lunixi\Sdk\Payment\CreateIntentRequest;

require_once __DIR__ . '/../_common/bootstrap.php';

$client = sample_client();

$request = (new CreateIntentRequest(10050, 'TRY', 'ORD-' . date('YmdHis')))
    ->withCallbackUrl(sample_env('LUNIXI_CALLBACK_URL', 'https://merchant.example.com/payments/lunixi/callback'))
    ->withCustomerId(sample_env('LUNIXI_CUSTOMER_ID', 'cust_demo_001'))
    ->withCardUserKey(sample_env('LUNIXI_CARD_USER_KEY', 'cust_demo_001'))
    ->withDescription('Demo checkout intent')
    ->withPaymentChannel('WEB')
    ->withPaymentMethod('CARD')
    ->withSettings([
        'paymentMethods' => ['card'],
        'threeD' => ['mode' => 'auto', 'forceAmountMinor' => '500000'],
        'buyerProtection' => ['enabled' => false],
    ])
    ->withBuyer(sample_buyer())
    ->withBillingAddress(sample_billing_address())
    ->withBasketItems(sample_basket_items(10050));

$intent = $client->payments()->createIntent($request, sample_idempotency_key('checkout-intent'));

sample_print([
    'paymentId' => $intent->paymentId(),
    'token' => $intent->token(),
    'checkoutFormContent' => $intent->checkoutFormContent(),
    'raw' => $intent->raw(),
]);
