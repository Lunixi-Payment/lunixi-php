<?php

declare(strict_types=1);

use Lunixi\Sdk\Payment\PaymentActionConfirmRequest;
use Lunixi\Sdk\Payment\PaymentMethod;
use Lunixi\Sdk\Payment\SplitPaymentPendingStep;
use Lunixi\Sdk\Payment\SplitPaymentRequest;

require_once __DIR__ . '/../_common/bootstrap.php';

// Split payment: one order paid with a stored card and Faturana Yansıt.
//   1. listPaymentMethods() gives the Faturana Yansıt providers that can be a
//      split payment leg (splitEligible());
//   2. createSplitPayment() starts it; the platform holds the card first and
//      charges Faturana Yansıt last, so the answer waits for its OTP;
//   3. confirmPaymentAction() sends the code, then the SAME request with the SAME
//      Idempotency-Key continues the payment until it is COMPLETED.
// Deliver the order on the main payment's webhook (payment.captured with settlementMode SPLIT).
$payments = sample_client()->payments();

$method = $payments->listPaymentMethods(PaymentMethod::MOBILE_BILLING)->find(PaymentMethod::MOBILE_BILLING);
$provider = null;
foreach ($method !== null ? $method->providers() : [] as $candidate) {
    if ($candidate->splitEligible()) {
        $provider = $candidate;
        break;
    }
}
if ($provider === null) {
    throw new RuntimeException('No Faturana Yansıt provider can be used in a split payment (form settings: split payments with other methods).');
}

$orderId = 'ORD-SPLIT-' . date('YmdHis');
$request = (new SplitPaymentRequest(10000, 'TRY', sample_required_env('LUNIXI_CARD_USER_KEY')))
    ->withOrderId($orderId)
    ->addCardLeg(sample_required_env('LUNIXI_STORED_CARD_TOKEN'), 6000)
    ->addMethodLeg(PaymentMethod::MOBILE_BILLING, $provider->providerCode(), 4000, [
        'gsmNumber' => sample_env('LUNIXI_BUYER_GSM', '+905350000000'),
    ]);
$key = 'split:' . $orderId;

$split = $payments->createSplitPayment($request, $key);
sample_print($split->response());

while (($pending = $split->pendingStep()) !== null && $pending->step() === SplitPaymentPendingStep::ACTION) {
    printf('Code sent for the %s leg: ', $pending->paymentMethod());
    $code = trim((string) fgets(STDIN));
    sample_print($payments->confirmPaymentAction((string) $pending->paymentId(), (string) $pending->actionId(), PaymentActionConfirmRequest::otp($code))->response());
    $split = $payments->createSplitPayment($request, $key);
    sample_print($split->response());
}

foreach ($split->steps() as $step) {
    printf("%d. %s %s %d %s\n", $step->sequence(), $step->paymentMethod(), $step->splitBehavior(), (int) $step->amount(), $step->status());
}
