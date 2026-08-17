<?php

declare(strict_types=1);

use Lunixi\Sdk\Payment\CardDetails;
use Lunixi\Sdk\Payment\DirectPaymentRequest;

require_once __DIR__ . '/../_common/bootstrap.php';

$request = (new DirectPaymentRequest(
    10050,
    'TRY',
    'ORD-DIRECT-3D-' . date('YmdHis'),
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
    ->withCallbackUrl(sample_env('LUNIXI_CALLBACK_URL', 'https://merchant.example.com/payments/3d-result'))
    ->withPaymentChannel('WEB')
    ->withPaymentGroup('PRODUCT')
    ->withDescription('Demo direct 3D payment');

$response = sample_client()->payments()->chargeCard3d($request, sample_idempotency_key('direct-3d'));

sample_print([
    'response' => $response,
    'redirectUrl' => $response['data']['threeDRedirectUrl'] ?? null,
]);
