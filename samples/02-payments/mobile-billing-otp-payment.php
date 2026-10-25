<?php

declare(strict_types=1);

use Lunixi\Sdk\Payment\DirectMethodPaymentRequest;
use Lunixi\Sdk\Payment\PaymentActionConfirmRequest;
use Lunixi\Sdk\Payment\PaymentActionType;
use Lunixi\Sdk\Payment\PaymentMethod;

require_once __DIR__ . '/../_common/bootstrap.php';

// Faturana Yansıt (charge to the mobile phone bill) from the merchant server:
//   1. listPaymentMethods() gives the providers (in your form settings order) and
//      the fields they ask for; with more than one provider your buyer picks one;
//   2. directMethodPayment() starts the payment; the response carries an OTP
//      action and the code is sent to the buyer's line;
//   3. confirmPaymentAction() sends the code the buyer entered
//      (type "resend" instead of a code to get a new one).
// The final result is also delivered by webhook (payment.captured / payment.failed).
$payments = sample_client()->payments();
$amount = 5000;

$method = $payments->listPaymentMethods()->find(PaymentMethod::MOBILE_BILLING);
if ($method === null) {
    throw new RuntimeException('Faturana Yansıt is not open for this account (form settings and provider connection).');
}
// With more than one provider let the buyer pick (here: LUNIXI_MOBILE_BILLING_PROVIDER, else the first).
$provider = $method->providers()[0];
foreach ($method->providers() as $candidate) {
    if ($candidate->providerCode() === sample_env('LUNIXI_MOBILE_BILLING_PROVIDER')) {
        $provider = $candidate;
    }
}
$fields = [];
foreach ($provider->inputFields() as $field) {
    if ($field->key() === 'gsmNumber') {
        $fields['gsmNumber'] = sample_env('LUNIXI_BUYER_GSM', '+905350000000');
    }
}

$request = (new DirectMethodPaymentRequest($amount, 'TRY', 'ORD-MB-' . date('YmdHis')))
    ->withMethodFields(PaymentMethod::MOBILE_BILLING, $provider->providerCode(), $fields)
    ->withBuyer(sample_buyer())
    ->withBasketItems(sample_basket_items($amount))
    ->withCallbackUrl('https://merchant.example/payment/callback');

$result = $payments->directMethodPayment($request, sample_idempotency_key('mobile-billing'));
sample_print($result->response());

while (($action = $result->action()) !== null && $action->type() === PaymentActionType::OTP) {
    printf('Code sent to %s (or "resend"): ', $action->maskedTarget() ?? 'the line');
    $answer = trim((string) fgets(STDIN));
    $result = $answer === 'resend'
        ? $payments->resendPaymentAction($result->paymentId(), $action->id())
        : $payments->confirmPaymentAction($result->paymentId(), $action->id(), PaymentActionConfirmRequest::otp($answer));
    sample_print($result->response());
}
