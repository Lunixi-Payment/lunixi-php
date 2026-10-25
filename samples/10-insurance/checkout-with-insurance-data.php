<?php

declare(strict_types=1);

/*
 * Insurance data on a basket item: send what the quote needs with the payment,
 * so the buyer is not asked for it again.
 *
 * 1. createIntent() (Checkout Form) with the ticket and its `insuranceData`:
 *    the root fields of the type's quote body (`insured`, `tickets` …). The
 *    list type object `tickets` is ONE object for this item, without
 *    `basketItemId`. Partial data is allowed; the form asks the buyer only for
 *    what is missing, and prices the insurance on its own when nothing is.
 *    The buyer still ticks the box and the insurer's consent.
 * 2. listProducts() with the payment: prefill() (from `insuranceContext`),
 *    providedFields() (from `insuranceData`, masked), quoteReady() and
 *    insuranceDataIssues() (data that could not be used; the payment goes on).
 * 3. Optional, Merchant API: createQuote() with `paymentIntentId` and no type
 *    fields: the recorded data is used, e.g. to show the price on your own
 *    page before the buyer opens the form.
 *
 * The data never reaches the payment provider; Lunixi stores it encrypted for
 * the insurance quote only and erases it after the payment intent expires.
 * The insured's details come from your checkout; here they are read from the
 * environment (see samples/.env.example). Needs the INSURANCE_SERVICE product
 * and an active insurance connection.
 */

use Lunixi\Sdk\Insurance\InsuranceExcludedQuoteOption;
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
$orderRef = 'ORD-INSDATA-' . gmdate('YmdHis');
$departureAt = gmdate('Y-m-d\TH:i:s\Z', time() + 14 * 86400);

$ticket = new BasketItem([
    'id' => $orderRef . '-T1',
    'price' => TICKET_PRICE,
    'name' => 'Izmir-Ankara train ticket',
    'category1' => 'ticket',
    'itemType' => BasketItem::TYPE_VIRTUAL,
    'insuranceCategory' => InsuranceItemCategory::TICKET,
    'insuranceTypes' => [INSURANCE_TYPE],
    // No personal data here: travel facts only.
    'insuranceContext' => ['transportMode' => 'TRAIN', 'departureAt' => $departureAt, 'originCity' => 'Izmir', 'destinationCity' => 'Ankara'],
    // Personal data the quote needs; leave out what you do not have.
    'insuranceData' => [
        'insured' => [[
            'identityType' => sample_required_env('LUNIXI_INSURED_IDENTITY_TYPE'),
            'identityNumber' => sample_required_env('LUNIXI_INSURED_IDENTITY_NUMBER'),
            'birthDate' => sample_required_env('LUNIXI_INSURED_BIRTH_DATE'),
            'email' => sample_required_env('LUNIXI_INSURED_EMAIL'),
            'phone' => sample_required_env('LUNIXI_INSURED_PHONE'),
        ]],
        'tickets' => [
            'carrier' => sample_env('LUNIXI_TICKET_CARRIER', 'Example Rail'),
            'priceAmount' => TICKET_PRICE,
            'pnr' => sample_required_env('LUNIXI_TICKET_PNR'),
            'ticketNumber' => sample_required_env('LUNIXI_TICKET_NUMBER'),
        ],
    ],
]);

$request = (new CreateIntentRequest(TICKET_PRICE, 'TRY', $orderRef))
    ->withCallbackUrl(sample_env('LUNIXI_CALLBACK_URL', 'https://merchant.example.com/payments/lunixi/callback'))
    ->withBuyer(sample_buyer())
    ->withBillingAddress(sample_billing_address())
    ->withBasketItems([$ticket]);
$intent = $lunixi->payments()->createIntent($request, 'checkout-' . $orderRef);

$products = $insurance->listProducts([
    'currency' => 'TRY',
    'paymentIntentId' => $intent->paymentId(),
    'insuranceType' => INSURANCE_TYPE,
]);
if ($products === []) {
    // Insurance is not offered; the payment goes on without it.
    sample_print(['paymentId' => $intent->paymentId(), 'insuranceOffered' => false]);
    return;
}
$product = $products[0];
sample_print([
    'paymentId' => $intent->paymentId(),
    'prefill' => $product->prefill(),
    'providedFields' => array_column($product->providedFields(), 'path'),
    'quoteReady' => $product->quoteReady(),
    'insuranceDataIssues' => $product->insuranceDataIssues(),
]);
if (!$product->quoteReady()) {
    return;
}

// Merchant API: nothing but the type, the currency and the payment.
$quote = $insurance->createQuote([
    'insuranceType' => INSURANCE_TYPE,
    'currency' => 'TRY',
    'paymentIntentId' => $intent->paymentId(),
]);
sample_print([
    'options' => array_values(array_map(
        static fn (InsuranceQuoteOption $o): array => [
            'quoteId' => $o->quoteId(),
            'premiumAmount' => $o->premiumAmount(),
            'premiumCollectedBy' => $o->premiumCollectedBy(),
            'expiresAt' => $o->expiresAt(),
        ],
        array_filter($quote->options(), static fn (InsuranceQuoteOption $o): bool => $o->status() === InsuranceQuoteStatus::QUOTED)
    )),
    'excludedOptions' => array_map(
        static fn (InsuranceExcludedQuoteOption $o): array => ['errorCode' => $o->errorCode(), 'errors' => $o->errors()],
        $quote->excludedOptions()
    ),
]);
