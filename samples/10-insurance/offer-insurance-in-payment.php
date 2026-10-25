<?php

declare(strict_types=1);

/*
 * Ticket cancellation insurance sold with your own Lunixi payment:
 * eligibility -> quote -> order -> premium item in the payment basket -> bind.
 *
 * 1. searchProducts() with the basket items the payment will carry: an empty
 *    list means insurance is not offered — continue the checkout without it.
 * 2. createQuote() with the same items and the type's fields. Policy-stage
 *    fields (PNR, ticket number, the insured's e-mail and phone) are sent here
 *    too; there is no later call to add them.
 * 3. Show the option (title, premium, terms) to the buyer; on acceptance
 *    createOrder() records the consent.
 * 4. Create the payment with the insured items plus the premium item
 *    (id = orderId, VIRTUAL, price = premium); `amount` is the sum.
 * 5. bindPayment(). Once the payment is captured the policy is requested on
 *    its own; the result arrives by the `insurance.policy.issued` or
 *    `insurance.policy.failed` webhook.
 *
 * The insured's details come from your checkout form; here they are read from
 * the environment (see samples/.env.example). Needs the INSURANCE_SERVICE
 * product on the key and an active insurance connection in the dashboard.
 */

use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Insurance\InsuranceClient;
use Lunixi\Sdk\Insurance\InsurancePremiumCollectedBy;
use Lunixi\Sdk\Insurance\InsuranceQuoteOption;
use Lunixi\Sdk\Insurance\InsuranceQuoteStatus;
use Lunixi\Sdk\Payment\BasketItem;
use Lunixi\Sdk\Payment\CreateIntentRequest;
use Lunixi\Sdk\Payment\InsuranceItemCategory;

require_once __DIR__ . '/../_common/bootstrap.php';

const INSURANCE_TYPE = 'TICKET_CANCELLATION';
const TICKET_PRICE = 100000;

$lunixi = sample_client();
$insurance = $lunixi->insurance();
$orderRef = 'ORD-INS-' . gmdate('YmdHis');
$ticketId = $orderRef . '-T1';
$departureAt = gmdate('Y-m-d\TH:i:s\Z', time() + 14 * 86400);

$ticket = new BasketItem([
    'id' => $ticketId,
    'price' => TICKET_PRICE,
    'name' => 'Izmir-Ankara train ticket',
    'category1' => 'ticket',
    'itemType' => BasketItem::TYPE_VIRTUAL,
    'insuranceCategory' => InsuranceItemCategory::TICKET,
    'insuranceTypes' => [INSURANCE_TYPE],
    'insuranceContext' => ['transportMode' => 'TRAIN', 'departureAt' => $departureAt],
]);

$products = $insurance->searchProducts([
    'currency' => 'TRY',
    'insuranceType' => INSURANCE_TYPE,
    'context' => ['items' => [$ticket]],
]);
if ($products === []) {
    sample_print(['insuranceOffered' => false]);
    return;
}

$quote = $insurance->createQuote([
    'insuranceType' => INSURANCE_TYPE,
    'currency' => 'TRY',
    'merchantOrderId' => $orderRef,
    'context' => ['items' => [$ticket]],
    'insured' => [[
        'identityType' => sample_required_env('LUNIXI_INSURED_IDENTITY_TYPE'),
        'identityNumber' => sample_required_env('LUNIXI_INSURED_IDENTITY_NUMBER'),
        'birthDate' => sample_required_env('LUNIXI_INSURED_BIRTH_DATE'),
        'email' => sample_required_env('LUNIXI_INSURED_EMAIL'),
        'phone' => sample_required_env('LUNIXI_INSURED_PHONE'),
    ]],
    'tickets' => [[
        'basketItemId' => $ticketId,
        'transportMode' => 'TRAIN',
        'carrier' => sample_env('LUNIXI_TICKET_CARRIER', 'Example Rail'),
        'priceAmount' => TICKET_PRICE,
        'departureAt' => $departureAt,
        'originCity' => 'Izmir',
        'destinationCity' => 'Ankara',
        'pnr' => sample_required_env('LUNIXI_TICKET_PNR'),
        'ticketNumber' => sample_required_env('LUNIXI_TICKET_NUMBER'),
    ]],
]);

// This flow collects the premium in your payment: pick a MERCHANT option.
$option = null;
foreach ($quote->options() as $candidate) {
    if ($candidate->status() === InsuranceQuoteStatus::QUOTED
        && $candidate->premiumCollectedBy() === InsurancePremiumCollectedBy::MERCHANT) {
        $option = $candidate;
        break;
    }
}
if (!$option instanceof InsuranceQuoteOption) {
    sample_print(['insuranceOffered' => false, 'options' => array_map(
        static fn (InsuranceQuoteOption $o): array => ['status' => $o->status(), 'premiumCollectedBy' => $o->premiumCollectedBy()],
        $quote->options()
    )]);
    return;
}

// Show $option->title(), $option->premiumAmount() and the terms to the buyer first.
try {
    $order = $insurance->createOrder([
        'quoteId' => $option->quoteId(),
        'consent' => InsuranceClient::consentFromQuote($option, new DateTimeImmutable()),
    ], 'ins-order-' . $orderRef);
} catch (ApiException $e) {
    // 409 insurance.quote.expired: the price is time-limited; quote again.
    sample_print(['status' => $e->getStatusCode(), 'code' => $e->getErrorCode()]);
    return;
}

$premiumItem = InsuranceClient::premiumBasketItem($order, 'Ticket cancellation insurance', 'insurance');
$request = (new CreateIntentRequest(TICKET_PRICE + $order->premiumAmount(), 'TRY', $orderRef))
    ->withCallbackUrl(sample_env('LUNIXI_CALLBACK_URL', 'https://merchant.example.com/payments/lunixi/callback'))
    ->withBuyer(sample_buyer())
    ->withBillingAddress(sample_billing_address())
    ->withBasketItems([$ticket, $premiumItem]);
$intent = $lunixi->payments()->createIntent($request, 'checkout-' . $orderRef);

$bound = $insurance->bindPayment($order->orderId(), $intent->paymentId(), 'ins-bind-' . $orderRef);
sample_print([
    'orderId' => $bound->orderId(),
    'paymentIntentId' => $bound->paymentIntentId(),
    'premiumAmount' => $bound->premiumAmount(),
    'status' => $bound->status(),
    'paymentMode' => $bound->paymentMode(),
]);
