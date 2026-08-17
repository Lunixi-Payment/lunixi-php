<?php

declare(strict_types=1);

use Lunixi\Sdk\Payment\CardDetails;
use Lunixi\Sdk\Payment\StoreCardRequest;

require_once __DIR__ . '/../_common/bootstrap.php';

// Verify-and-store opens a mandatory 3DS authorization. The card is vaulted only
// after successful cardholder authentication.
$request = (new StoreCardRequest(
    new CardDetails([
        'cardHolderName' => 'Ada Yilmaz',
        'cardNumber' => '5400000000000004',
        'expireMonth' => '12',
        'expireYear' => '28',
        'cvcNumber' => '123',
        'cardName' => 'Ada demo card',
    ]),
    sample_env('LUNIXI_CARD_USER_KEY', 'cust_demo_001'),
    sample_env('LUNIXI_CALLBACK_URL', 'https://merchant.example.com/cards/lunixi/callback')
))->withCurrency('TRY');

$response = sample_client()->payments()->storeCard($request, sample_idempotency_key('store-card'));

sample_print([
    'response' => $response,
    'redirectUrl' => $response['data']['threeDRedirectUrl'] ?? null,
]);
