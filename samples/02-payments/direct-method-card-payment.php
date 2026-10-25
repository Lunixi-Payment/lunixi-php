<?php

declare(strict_types=1);

use Lunixi\Sdk\Payment\CardDetails;
use Lunixi\Sdk\Payment\DirectMethodPaymentRequest;

require_once __DIR__ . '/../_common/bootstrap.php';

// A card from the merchant server: directMethodPayment() is the only
// server-side card charge. withForce3D(true) makes it a 3D Secure payment and
// the response carries a REDIRECT action (send the buyer to its redirectUrl()).
// Without withForce3D() the card is charged without 3D Secure (the bank may
// still ask for it with the same REDIRECT action).
$amount = 10050;
$request = (new DirectMethodPaymentRequest($amount, 'TRY', 'ORD-DMP-CARD-' . date('YmdHis')))
    ->withCard(new CardDetails([
        'cardHolderName' => 'Ada Yilmaz',
        'cardNumber' => '5400000000000004',
        'expireMonth' => '12',
        'expireYear' => '28',
        'cvcNumber' => '123',
    ]))
    ->withForce3D(true)
    ->withCallbackUrl(sample_env('LUNIXI_CALLBACK_URL', 'https://merchant.example.com/payments/3d-result'))
    ->withBuyer(sample_buyer())
    ->withBillingAddress(sample_billing_address())
    ->withBasketItems(sample_basket_items($amount))
    ->withPaymentChannel('WEB')
    ->withPaymentGroup('PRODUCT')
    ->withDescription('Demo card payment');

$result = sample_client()->payments()->directMethodPayment($request, sample_idempotency_key('direct-method-card'));

sample_print([
    'response' => $result->response(),
    'redirectUrl' => $result->action() !== null ? $result->action()->redirectUrl() : null,
]);
