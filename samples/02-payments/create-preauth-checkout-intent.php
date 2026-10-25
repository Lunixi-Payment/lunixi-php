<?php

declare(strict_types=1);

use Lunixi\Sdk\Payment\CreateIntentRequest;

require_once __DIR__ . '/../_common/bootstrap.php';

// Pre-authorization: the amount is held on the card and the payment becomes
// AUTHORIZED. Take it with capture-authorized-payment.php or release it with
// void-authorized-payment.php (LUNIXI_PAYMENT_ID = the paymentId printed here).

$client = sample_client();

$request = (new CreateIntentRequest(10050, 'TRY', 'ORD-PRE-' . date('YmdHis')))
    ->withPreauth(true)
    ->withCallbackUrl(sample_env('LUNIXI_CALLBACK_URL', 'https://merchant.example.com/payments/lunixi/callback'))
    ->withCustomerId(sample_env('LUNIXI_CUSTOMER_ID', 'cust_demo_001'))
    ->withDescription('Demo pre-authorized checkout intent')
    ->withPaymentChannel('WEB')
    ->withBuyer(sample_buyer())
    ->withBillingAddress(sample_billing_address())
    ->withBasketItems(sample_basket_items(10050));

$intent = $client->payments()->createIntent($request, sample_idempotency_key('preauth-intent'));

sample_print([
    'paymentId' => $intent->paymentId(),
    'token' => $intent->token(),
    'checkoutFormContent' => $intent->checkoutFormContent(),
]);
