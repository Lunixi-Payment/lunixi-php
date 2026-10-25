# Lunixi PHP SDK

Framework-agnostic PHP client for the Lunixi payment gateway. Used standalone
(`composer require lunixi/php-sdk`) or bundled — namespace-scoped via PHP-Scoper —
inside the WordPress/WooCommerce, OpenCart and other Lunixi integrations. For
Laravel use [`lunixi/laravel`](https://github.com/Lunixi-Payment/lunixi-laravel#readme), which builds on this SDK.

All money/auth/webhook logic lives here once; the platform plugins are thin
adapters over this SDK.

- **Requires:** PHP 7.4+, `ext-sodium`, `ext-curl`, `ext-json`
- **License:** MIT

## Status

Payment SDK v1 implemented & verified (**300 unit tests, 2041 assertions**, PHP 8.4):

| Component | Purpose |
|---|---|
| `Configuration` | Immutable, validated config (base URL, key id, Ed25519 key, env, retries). |
| `Auth\CanonicalRequest` | Builds the exact canonical string the gateway signs. |
| `Auth\Ed25519Signer` | Signs requests (libsodium); accepts the dashboard PKCS#8 PEM; derives the public key; can generate keypairs (onboarding). |
| `Auth\TokenManager` + `TokenStore*` | Signed `/auth/token` exchange → cached bearer; durable store injectable (WP transient). |
| `Http\HttpClientInterface` + `CurlHttpClient` + `HttpResponse` | Swappable transport (the WP plugin injects a `wp_remote_request` adapter). |
| `ApiClient` | Authenticated requests: bearer + Ed25519 step-up + Idempotency-Key + money-safe retry (401 re-auth; 5xx/network retried ONLY when idempotent). |
| `Payment\PaymentClient` | `createIntent` / `directMethodPayment` (card and other methods) / BIN-installments / stored cards / `capture` / `refund` / `void` / `get` / `list` against the verified gateway routes. |
| `Payment\PaymentLinkClient` | Payment links (`/api/v1/payments/links`): create, list/iterate, get, update, pause/resume/deactivate, send, QR, attempts, payments, image upload. Every gateway call is signed (the routes accept the API-key signature only); the image PUT to storage carries no credentials. |
| `Payment\*` (DTOs) | `CreateIntentRequest` (current CreatePaymentIntentDto fields) + `DirectMethodPaymentRequest`/`DirectPaymentRequest` (marketplace 2D/3D base)/`CardDetails`/`InstallmentOptionsRequest`/`StoreCardRequest` + `Buyer`/`Address`/`BasketItem` (`itemType` PHYSICAL/VIRTUAL; optional insurance fields for `createIntent`, checked before sending, categories in `InsuranceItemCategory`); result objects `CheckoutIntent`/`PaymentIntent`/`PaymentList`; `PaymentStatus`/`Currency` constants. Payment links: `CreatePaymentLinkRequest`/`PaymentLinkItem`/`PaymentLinkCustomField`, `PaymentLink`/`PaymentLinkList`/`PaymentLinkQrCode`, `PaymentLinkUsage`/`PaymentLinkAmountMode`/`PaymentLinkState`/`PaymentLinkAttemptState`. |
| `Wallet\WalletClient` | Closed-loop wallet (`/api/v1/wallet/*`, 60 routes): `ping()` plus sub-clients `programs`, `endUsers`, `wallets`, `deposits`, `corporate`, `fees`, `transfers`, `withdrawals`, `operations`, `topups`, `payments`, `qr`, `bulkPayouts`. Every call is signed; requests are checked against `WalletRoutes` (unknown/missing field, non-digit amount, missing Idempotency-Key) before anything is sent. Lists return `WalletPage`; the QR image `WalletQrImage`; constants in `WalletAccountType`, `WalletKycLevel`, `WalletOtpChannel`, `WalletQrType`, `WalletQrImageFormat`, `WalletBulkTargetType`, `WalletEndUserStatus`, `WalletCorporateAccountStatus`. |
| `BankConnect\BankConnectClient` | Bank accounts (`/api/v1/bank-connect`, product BANK_CONNECT): `listBanks`, `listConnections`/`getConnection`, `listAccounts`/`getAccount`, `listBalances`, `listTransactions`/`getTransaction` (each list with an `iterate…`), `requestSync`. Every call is signed; filters are checked before sending (unknown filter, value outside its set, amount or 92-day window rule). Results: `BankConnectPage`, `BankConnectBank`, `BankConnectConnection`, `BankConnectAccount` (+ `BankConnectFreshness`), `BankConnectBalance`, `BankConnectTransaction`, `BankConnectSyncRequest`; constants `BankConnectionStatus`, `BankAccountStatus`, `BankTransactionDirection`, `BankTransactionType`, `BankAvailability`, `BankCapability`, `BankSyncStatus`; webhook `BankConnectEvents` + `BankAccountTransactionReceived`. Money movement (scope `bank-connect:transfer`, key with an IP allowlist): `->transfers()` (`BankTransferClient`: `listPurposes`, `create`, `list`/`iterate`, `get`) and `->fx()` (`BankFxClient`: `listRates`, `createQuote`, `createOrder`, `listOrders`/`iterateOrders`, `getOrder`); `Idempotency-Key` generated when not passed; routes `BankTransferRoutes`; webhook `BankTransferEvents` + `BankTransferEvent`. |
| `Sanction\SanctionClient` | Sanctions screening (`/api/v1/sanction/*`, product SANCTION_SERVICE, `sanction:view_results`; bearer only): `screenName` → `ScreenResult`, `screenWallet` → `WalletScreenResult`, `screenPublicProcurementBan` → `ProcurementBanScreenResult`, `screenAccount` / `screenTransaction` / `screenAdverseMedia` → `ScreenOutcome`; history, monitoring and entity-intelligence reads (`SanctionPage` lists with `iterate…`). `isClear()` only with no match and `coverageStatus` COMPLETE; a screening that cannot run (503) throws. Requests are checked against `SanctionRoutes` (the Node SDK's `SANCTION_ROUTES`); constants `RiskDomain`, `ScreenMode`, `WalletScreenMode`, `SubjectType`, `CoverageStatus`, `HitType`. |
| `Payment\PromissoryNote*` + `Reference\ReferenceClient` | Senetle Ödeme (`PROMISSORY_NOTE`): plan rules `PromissoryNotePlan`, results `PromissoryNotes`/`PromissoryNote`/`PromissoryNoteDocument` (PDF), constants `PromissoryNoteStatus`/`PromissoryNoteCancelResult`/`PromissoryNoteEvents`, webhook `PromissoryNoteEvent`; Türkiye address codes for ADDRESS fields (`listProvinces`/`listDistricts`/`listNeighborhoods`). |
| `Insurance\InsuranceClient` | Insurance (`/api/v1/insurance`, product INSURANCE_SERVICE): `listProducts`/`searchProducts`, `listReferenceValues`, `createQuote`/`getQuote`, `createOrder`/`listOrders`/`getOrder`, `bindPayment`, `confirmPayment`, `issuePolicy`, `cancelOrder`, `refundPremium`, `getPolicyDocument`/`getPolicyReceipt`/`getPolicyCertificate` (PDF), `createLead`/`listLeads`/`getLead`, `getReconciliationSummary`/`listReconciliationOrders`/`exportReconciliation` (CSV); each list with an `iterate…`. Every call is signed; fields, filters and the `Idempotency-Key` are checked before sending. Helpers `consentFromQuote()` and `premiumBasketItem()`. Results: `InsuranceProduct`, `InsuranceQuoteSet`/`InsuranceQuoteOption` (`InsuranceUnderwriter`, `InsuranceExcludedQuoteOption`), `InsuranceOrder`, `InsuranceLead`, `InsurancePage`, `InsuranceReconciliation*`, `InsurancePolicyDocument`; value sets `Insurance*Status` (`InsuranceLeadStatus` included), `InsurancePremiumCollectedBy`, `InsurancePaymentMode`, `InsuranceReferenceKind`; webhook `InsuranceEvents` + `InsuranceWebhookData` (order events) and `InsuranceLeadWebhookData` (lead events). |
| `Webhook\WebhookVerifier` + `WebhookEvent` | Verifies inbound webhooks fail-closed: v2 = HMAC-SHA256 over the exact raw body (`v2=` header items; during a secret rotation any one matching item is enough). |
| `Exception\*` | Typed error hierarchy. |
| `LunixiClient` | Facade: `->payments()`, `->paymentLinks()`, `->wallet()`, `->bankConnect()`, `->sanction()`, `->reference()`, `->insurance()`, `->webhooks()`, `->api()`, `->tokens()`, `->signer()`. |

Next: the `wordpress/` plugin (bundles this SDK via PHP-Scoper; WC gateway with
HPOS + Blocks; webhook router; embeds the JS checkout SDK). Installments deferred.

## Quick examples

Runnable, production-oriented examples live in [`samples/`](samples/). Start
with `samples/.env.example`, then run a category sample such as:

```bash
php samples/02-payments/create-checkout-intent.php
php samples/04-reporting/installment-quote.php
```

### Sign & verify (auth)

```php
use Lunixi\Sdk\Auth\CanonicalRequest;
use Lunixi\Sdk\Auth\Ed25519Signer;

$signer = new Ed25519Signer($merchantPrivateKeyPem); // from the Lunixi dashboard
$date   = gmdate('Y-m-d\TH:i:s\Z');
$nonce  = bin2hex(random_bytes(16));

$canonical = CanonicalRequest::build('POST', '/auth/token', $date, $nonce);
$headers = [
    'X-Key-Id'    => $kid,
    'X-Date'      => $date,
    'X-Nonce'     => $nonce,
    'X-Signature' => $signer->sign($canonical),
];
```

### Verify an inbound webhook (fail-closed)

```php
use Lunixi\Sdk\Webhook\WebhookVerifier;
use Lunixi\Sdk\Exception\WebhookVerificationException;

try {
    $event = (new WebhookVerifier())->verify($rawBody, $requestHeaders, $endpointSecret);
} catch (WebhookVerificationException $e) {
    http_response_code(400);
    return; // never act on an unverified payload
}

// dedupe by $event->id() (delivery is at-least-once), then handle:
if ($event->matches('payment.*')) {
    // update order from $event->payload()  (webhook is the source of truth)
}
```

### Server-side payments

```php
use Lunixi\Sdk\LunixiClient;
use Lunixi\Sdk\Payment\CreateIntentRequest;

$lunixi = LunixiClient::create([ /* baseUrl, keyId, privateKey, environment */ ]);

// 1) Create an intent on the server, hand the token to the browser SDK.
$intent = $lunixi->payments()->createIntent(
    (new CreateIntentRequest(1000, 'TRY', 'WC-1042'))->withCallbackUrl('https://shop.example/lunixi/return')
);
$token = $intent->token(); // → embedded JS SDK renders/submits the form

// 2) Admin actions (step-up + Idempotency-Key handled for you).
$lunixi->payments()->capture('pi_123', 500, $stableKey);   // partial capture
$lunixi->payments()->refund('pi_123', 300, 'return', $stableKey);
$payment = $lunixi->payments()->get('pi_123');             // reconciliation
if ($payment->isCaptured()) { /* … */ }
```

### Amounts

`amount` is the only amount field of a payment request: `CreateIntentRequest`
(also for the marketplace intent), `DirectPaymentRequest` (the marketplace 2D/3D
charge) and `DirectMethodPaymentRequest` (`direct/payment`, card included) all
take it as their first argument.

- It is the basket total in minor units: an integer, at least 1 (`1000` = 10.00
  TRY). A smaller value throws `ConfigurationException`.
- With basket items, the items' `price` values must add up to `amount`.
- `amount` does not include an installment fee. For an installment charge send
  the installment count and the quote token from `installmentOptions()`
  (`withInstallment($count, $quoteToken)` on the direct and marketplace requests; on the hosted
  form the buyer picks the installment). The system adds the fee to the charged
  amount.
- `price` and `paidPrice` are no longer request fields. A raw array that carries
  either (`createMarketplaceIntent()`, `chargeMarketplaceCard()`)
  throws `ConfigurationException` before sending; the gateway answers it with 400
  `payment.amount.field_removed`.
- Marketplace: the `MarketplaceSeller` amounts must add up to `amount`.
- In responses and webhooks `amount` is the charged amount, installment fee
  included.

### Pre-authorization (preauth)

`withPreauth(true)` holds the amount on the card instead of capturing it. The
payment becomes `AUTHORIZED`; take it with `capture()` or release it with
`void()`. It is available on `CreateIntentRequest` (hosted checkout and the
marketplace intent), on `DirectMethodPaymentRequest` (`direct/payment`, card
included) and on `DirectPaymentRequest` (`chargeMarketplaceCard2d`/`3d`).
Without it, or with `withPreauth(false)`, the amount is captured immediately.

```php
$intent = $lunixi->payments()->createIntent(
    (new CreateIntentRequest(1000, 'TRY', 'WC-1042'))->withPreauth(true)
);
// later, once the payment is AUTHORIZED:
$lunixi->payments()->capture($intent->paymentId(), null, $stableKey);
```

### Payment methods (Faturana Yansıt, wallets, promissory notes, card)

`directMethodPayment()` starts a payment with any payment method from your
server (`POST /api/v1/payments/direct/payment`), card included. `createIntent()`
works unchanged.

```php
use Lunixi\Sdk\Payment\DirectMethodPaymentRequest;
use Lunixi\Sdk\Payment\PaymentActionConfirmRequest;
use Lunixi\Sdk\Payment\PaymentActionType;
use Lunixi\Sdk\Payment\PaymentMethod;

// 1) Which methods are open, which providers, which fields?
$method = $lunixi->payments()->listPaymentMethods()->find(PaymentMethod::MOBILE_BILLING);
$provider = $method->providers()[0];   // the one your buyer picked; inputFields(): gsmNumber

// 2) Start the payment (Idempotency-Key required).
$request = (new DirectMethodPaymentRequest(5000, 'TRY', 'WC-1042'))
    ->withMethodFields(PaymentMethod::MOBILE_BILLING, $provider->providerCode(), ['gsmNumber' => '+905321234567'])
    ->withCallbackUrl('https://shop.example/lunixi/return');
$result = $lunixi->payments()->directMethodPayment($request, $stableKey);

// 3) The buyer must act (HTTP 202): confirm the code, or resend it.
$action = $result->action();
if ($action !== null && $action->type() === PaymentActionType::OTP) {
    $done = $lunixi->payments()->confirmPaymentAction(
        $result->paymentId(),
        $action->id(),
        PaymentActionConfirmRequest::otp($codeFromBuyer)
    );
}
```

- `listPaymentMethods(PaymentMethod::MOBILE_BILLING)` lists that method only
  (`?paymentMethod=`; empty when it is not open, 400 `payment.method.unknown`
  for an unknown code). `listPaymentMethods()` lists every open method.
- Methods and providers come in the order set in your form settings (the
  platform's default order when you set none); show them in this order. A
  provider turned off only in the checkout form stays in the list and can be
  used through the API.
- `withMethodFields($method, $providerCode, $fields)`: the fields are the
  provider's `inputFields()`; `$providerCode` is required when
  `requiresProviderCode()` (more than one provider). Card: `withCard($card)`,
  `withForce3D(true)` for 3D Secure (the result carries a `REDIRECT` action,
  `redirectUrl()`), `withInstallment($count, $quoteToken)`.
- `resendPaymentAction($paymentId, $actionId)` sends a new code; confirm with
  the new `action()->id()`.
- A declined payment is HTTP 400 and throws `ApiException`;
  `new MethodPaymentResult($e->getResponseBody() ?? [])` reads its fields. A wrong
  code is HTTP 422 and the action stays open (`details.attemptsRemaining`).
- `withPreauth(true)` holds the amount; a method that does not support it is
  refused with 422 `payment.preauth.unsupported_for_method`.
- The final result also arrives by webhook with `paymentMethod` and
  `providerCode`; `get()` returns them as `paymentMethod()` and `action()`.
- A merchant server confirms with its signature. The SDK never sends the
  checkout token fields (`token`, `checkoutSessionProof`).

#### Who picks the provider

In the Checkout Form the buyer always picks the provider of a non-card method
(with one provider no picker is shown and that provider is used).
`providerSelectionMode()` (`ProviderSelectionMode`) is the API behaviour, when
your own page or server sends the payment:

- `BUYER`: with more than one provider `requiresProviderCode()` is true and a
  request without `providerCode` is refused with 400 `payment.provider.required`.
  Show the providers (`displayName()`, `logoUrl()`) and send the chosen one.
- `PLATFORM` (Faturana Yansıt): `providerCode` may be null. The platform then
  picks one of the connected providers before any money moves and keeps it for
  the payment. When you let your buyer pick (as the Checkout Form does with more
  than one provider), send the chosen `providerCode`. When none of the connected
  providers can take a payment without `providerCode` the answer is 422
  `payment.provider.none_eligible` (`ApiException`, `getErrorCode()`): nothing
  was charged and `details.candidates[]` gives each provider's `outcome`
  (`INELIGIBLE`, or `UNAVAILABLE` / `NOT_CHECKED`, which may succeed on a later
  try).

`requiresProviderCode()` (`providerSelection` REQUIRED) remains the rule for
when `providerCode` must be sent.

```php
use Lunixi\Sdk\Payment\ProviderSelectionMode;

$method = $lunixi->payments()->listPaymentMethods(PaymentMethod::WALLET)->find(PaymentMethod::WALLET);

$providerCode = null;
if (count($method->providers()) > 1) {
    $providerCode = $codeTheBuyerPicked;            // your page lists displayName() + logoUrl(), in this order
} elseif ($method->requiresProviderCode()) {
    $providerCode = $method->providers()[0]->providerCode();
}
// PLATFORM without a pick: $providerCode stays null; the platform picks the provider.

$request = (new DirectMethodPaymentRequest(5000, 'TRY', 'WC-1043'))
    ->withMethodFields(PaymentMethod::WALLET, $providerCode, $fields);
```

### Split payments

`createSplitPayment()` funds one payment with several legs
(`POST /api/v1/payments/split`): stored cards of the same owner and, when split
payments with other methods are enabled in your form settings, non-card methods
with the same block as `direct/payment`. The platform sets the order of the
legs and returns it as `steps()`. Leg amounts are minor units and add up to
`totalAmount`; 1 to 5 legs. The builder checks the rules before sending
(`ConfigurationException`): one leg per card and per provider, card fields only
on card legs, `callbackUrl` with a 3D Secure leg, an installment quote from 2
installments, `noteCount` (no schedule) on a promissory-note leg.

Docs: [Split Payments](https://docs.lunixi.com/payments/split-payments),
[Multiple Cards](https://docs.lunixi.com/payments/split-payments/multi-card),
[Multiple Payment Methods](https://docs.lunixi.com/payments/split-payments/multi-method).

#### Multiple cards

Card legs only. Each card leg has its own 3D Secure (`$force3D`, then
`withCallbackUrl()` is required) and its own installments. An installment
quote is taken with that leg's amount, without an intent.

```php
use Lunixi\Sdk\Payment\InstallmentOptionsRequest;
use Lunixi\Sdk\Payment\SplitPaymentRequest;

// Card B in 3 installments: quote with the leg's amount and the stored card's BIN.
$quote = $lunixi->payments()->installmentOptions(
    (new InstallmentOptionsRequest(40000, 'TRY'))->withBinOrPan($cardBBin)->withInstallment(3)
);

$request = (new SplitPaymentRequest(100000, 'TRY', $cardUserKey))
    ->withOrderId('WC-1050')
    ->withCallbackUrl('https://shop.example/lunixi/return')
    ->addCardLeg($cardAToken, 60000, true)                                         // 3D Secure
    ->addCardLeg($cardBToken, 40000, false, 3, $quote['data']['quoteToken']);      // 3 installments

$key = 'split:WC-1050';                       // the identity of this split payment; reuse it on every call
$split = $lunixi->payments()->createSplitPayment($request, $key);
// Card A waits for 3D Secure: $split->pendingStep()->step() === 'THREE_DS' (see "Completing the pending step").
```

`$cardAToken` / `$cardBToken` are stored card tokens of `$cardUserKey`. The
cards are processed in the order you add them.

#### Multiple payment methods

Several cards together with non-card methods. Use providers with
`splitEligible()` from `listPaymentMethods()`; `splitBehavior()`
(`SplitBehavior`) tells how a provider is charged. The platform holds the cards
first (each with its own 3D Secure and installments), then charges reversible
methods (wallet), and the non-reversible method (Faturana Yansıt, promissory
note; at most one) last. More than one card needs the "Pay with Multiple
Cards" switch in your form settings; four legs need "Maximum parts" of at
least 4.

```php
use Lunixi\Sdk\Payment\PaymentMethod;
use Lunixi\Sdk\Payment\SplitPaymentRequest;

$methods = $lunixi->payments()->listPaymentMethods();
$splitProvider = static function (string $code) use ($methods): ?string {
    $method = $methods->find($code);
    foreach ($method !== null ? $method->providers() : [] as $provider) {
        if ($provider->splitEligible()) {
            return $provider->providerCode();
        }
    }
    return null;
};

// 2 cards + wallet + Faturana Yansıt = 100000
$request = (new SplitPaymentRequest(100000, 'TRY', $cardUserKey))
    ->withOrderId('WC-1051')
    ->withCallbackUrl('https://shop.example/lunixi/return')
    ->addCardLeg($cardAToken, 30000, true)
    ->addCardLeg($cardBToken, 25000, false, 3, $cardBQuoteToken)
    ->addMethodLeg(PaymentMethod::WALLET, (string) $splitProvider(PaymentMethod::WALLET), 20000, ['gsmNumber' => $buyerGsm])
    ->addMethodLeg(PaymentMethod::MOBILE_BILLING, (string) $splitProvider(PaymentMethod::MOBILE_BILLING), 25000, ['gsmNumber' => $buyerGsm]);

$key = 'split:WC-1051';
$split = $lunixi->payments()->createSplitPayment($request, $key);
```

`$buyerGsm` is the number your buyer entered for the wallet and Faturana Yansıt
(the provider's `inputFields()`). The method fields are not stored: send the
same request with the same key on every call.

#### Reading the steps

`steps()` is the order the platform set, one `SplitPaymentStep` per leg;
`pendingStep()` is what the payment waits for, `null` when nothing waits on
you. `expiresAt()` is the end of the payment window (`null` for cards only or
once the non-reversible leg has started).

```php
foreach ($split->steps() as $step) {
    $leg = $step->isCard() ? 'card' : sprintf('%s (%s)', $step->paymentMethod(), $step->providerCode());
    printf(
        "%d. %s %s %d %s%s\n",
        $step->sequence(),
        $leg,
        $step->splitBehavior(),
        (int) $step->amount(),
        $step->status(),
        $step->isCurrent() ? ' <- waiting' : ''
    );
}
```

#### Completing the pending step

`pendingStep()->step()` (`SplitPaymentPendingStep`) tells you what to do. After
each step call `createSplitPayment()` again with the same request and the same
key: no new payment is created (`isDuplicate()`) and the answer carries the
current steps.

- `THREE_DS`: send the buyer to `redirectPath()` on `https://api-gateway.lunixi.com`;
  the buyer returns to your `callbackUrl`.
- `ACTION`: the buyer's code for a non-card leg. Confirm it with
  `confirmPaymentAction($pending->paymentId(), $pending->actionId(), PaymentActionConfirmRequest::otp($code))`;
  `resendPaymentAction()` sends a new code.
- `START_METHOD`: the next non-card leg is waiting to start; call again.
- `RESULT_PENDING`, `CREDIT_APPROVAL`: wait. Read it later with
  `getSplitPayment($splitPaymentId)`; the result also arrives by webhook.

```php
use Lunixi\Sdk\Payment\PaymentActionConfirmRequest;
use Lunixi\Sdk\Payment\SplitPaymentPendingStep;
use Lunixi\Sdk\Payment\SplitPaymentRequest;
use Lunixi\Sdk\Payment\SplitPaymentResult;

/** @return array{split: SplitPaymentResult, redirectTo?: string, askBuyerForCode?: bool} */
function nextSplitStep($payments, SplitPaymentRequest $request, string $key, ?string $codeFromBuyer = null): array
{
    $split = $payments->createSplitPayment($request, $key);
    $pending = $split->pendingStep();
    if ($pending === null) {
        return ['split' => $split];
    }
    switch ($pending->step()) {
        case SplitPaymentPendingStep::THREE_DS:
            return ['split' => $split, 'redirectTo' => 'https://api-gateway.lunixi.com' . $pending->redirectPath()];
        case SplitPaymentPendingStep::ACTION:
            if ($codeFromBuyer === null) {
                return ['split' => $split, 'askBuyerForCode' => true];
            }
            $confirm = PaymentActionConfirmRequest::otp($codeFromBuyer);
            if ($pending->action() !== null && $pending->action()->consentRequired()) {
                $confirm = $confirm->withConsentAccepted(true);
            }
            $payments->confirmPaymentAction((string) $pending->paymentId(), (string) $pending->actionId(), $confirm);
            return nextSplitStep($payments, $request, $key);
        case SplitPaymentPendingStep::START_METHOD:
            return nextSplitStep($payments, $request, $key);
        default:
            return ['split' => $split];        // RESULT_PENDING, CREDIT_APPROVAL: wait for the webhook
    }
}
```

`$payments` is `$lunixi->payments()`.

#### Errors (`split_pay.*`)

- A rule broken before sending throws `ConfigurationException`; nothing was sent.
- Rule violations found by the gateway are HTTP 422 with a
  `split_pay.validation.*` code (`SplitPayErrorCode`, `ApiException`); nothing
  was charged. `details` in `getResponseBody()` gives the limit (`min`, `max`,
  `minLegAmount`, `minAmount`, `maxAmount`).
- While the payment runs the gateway answers HTTP 400 with the status as
  `code`; `createSplitPayment()` returns that answer
  (`SplitPaymentStatus::IN_PROGRESS`) instead of throwing.
- A failed payment (`VOIDED`, `FAILED`, `COMPENSATING`,
  `RECONCILIATION_REQUIRED`) throws `ApiException`;
  `new SplitPaymentResult($e->getResponseBody() ?? [])` reads it. The same key
  keeps returning this payment; a new attempt needs a new key.
- `split_pay.step.busy` (409): another request is processing the payment;
  retry shortly with the same key. `split_pay.step.not_allowed` (409): read the
  current step and follow it (`details.reason`).

```php
use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Payment\SplitPayErrorCode;
use Lunixi\Sdk\Payment\SplitPaymentResult;

try {
    $result = nextSplitStep($lunixi->payments(), $request, $key, $codeFromBuyer);
    // show $result['redirectTo'] / ask for the code / wait for the webhook
} catch (ApiException $e) {
    $body = $e->getResponseBody() ?? [];
    if (isset($body['data']['splitPaymentId'])) {
        $failed = new SplitPaymentResult($body);   // the split payment failed: $failed->status(), $failed->steps()
    } elseif ($e->getErrorCode() === SplitPayErrorCode::STEP_BUSY) {
        // retry in a few seconds with the same request and key
    } elseif ($e->getErrorCode() === SplitPayErrorCode::LEG_OUTSIDE_PROVIDER_LIMITS) {
        $limits = $body['details'] ?? [];          // minAmount, maxAmount: move the leg into this range
    } elseif (strpos((string) $e->getErrorCode(), 'split_pay.') === 0) {
        // see the Split Payments error table for what to do
    } else {
        throw $e;
    }
}
```

Error codes and what to do: [Split Payments › Errors](https://docs.lunixi.com/payments/split-payments#errors).

#### Refunds, listing and webhooks

- `getSplitPayment($id)` returns the legs with `chargeAmount()`,
  `capturedAmount()`, `refundedAmount()`, `refundState()` and `dispute()`, and
  `refundableAmount()` / `nonRefundableAmount()` / `hasDispute()` of the main
  payment. `listSplitPayments(['status' => ['COMPLETED', 'VOIDED'], 'limit' => 25])`
  pages with `endCursor()`.
- `refundSplitPayment($id, $amount, $reason, $key)` refunds the main payment:
  card legs first (partial allowed), then the other refundable legs in full;
  a non-reversible leg is never refunded. `split_pay.refund.incomplete`
  (`ApiException`) carries the per-leg result; retry with the same key. The main
  payment of a Checkout Form split is refunded with `refund()`.
- `list(['includeLegs' => true])` also returns each leg as a row;
  `PaymentIntent::split()` marks the main payment (`ROLE_PARENT`) and the legs
  (`ROLE_LEG`).
- Webhooks: deliver the order on the main payment's `payment.captured` /
  `payment.failed` (`settlementMode: SPLIT`, `legs[]`). `SplitPaymentEvents`
  lists the split events; add `SplitPaymentEvents::LEG_EVENTS_SUBSCRIPTION` to
  an endpoint's event types to also receive each leg's events.

### Senetle Ödeme (promissory notes)

`paymentMethod: PROMISSORY_NOTE` issues promissory notes (or payment orders,
by your provider connection settings) through the provider's API. There is no
buyer action: on success the payment is `CAPTURED` at once. The money does not
pass through Lunixi; each note is collected at its due date from the debtor to
your own account.

```php
use Lunixi\Sdk\Payment\DirectMethodPaymentRequest;
use Lunixi\Sdk\Payment\PaymentMethod;
use Lunixi\Sdk\Payment\PromissoryNoteDocument;

$request = (new DirectMethodPaymentRequest(300000, 'TRY', 'WC-2001'))
    ->withMethodFields(PaymentMethod::PROMISSORY_NOTE, null, [
        'debtorType' => 'INDIVIDUAL',
        'birthDate' => '1990-01-01',
        'address' => ['provinceCode' => '06', 'districtCode' => '1001', 'neighborhoodCode' => '2001', 'line' => 'Example Street 1'],
    ])
    ->withPromissoryNoteSchedule([
        ['amount' => 100000, 'dueDate' => '2030-01-15'],
        ['amount' => 200000, 'dueDate' => '2030-02-15'],
    ])
    ->withBuyer($buyer);
$result = $lunixi->payments()->directMethodPayment($request, $stableKey);   // status CAPTURED, no action

$notes = $lunixi->payments()->getPromissoryNotes($result->paymentId());
$pdf = $lunixi->payments()->downloadPromissoryNoteDocument($result->paymentId(), PromissoryNoteDocument::LOCALE_TR);
file_put_contents('notes.pdf', $pdf->bytes());
```

- Debtor and guarantor fields are the provider's `inputFields()`. Field types
  include ADDRESS (an associative array; codes from `$lunixi->reference()`),
  GROUP (a list of associative arrays, e.g. guarantors; `itemFields()`,
  `minItems()`, `maxItems()`) and VKN. A field with `requiredWhen()` is required
  only when that sibling field has that value, and must be left out otherwise.
- The plan is either `withPromissoryNoteSchedule($rows)` (amounts in minor units
  adding up to `amount`, `YYYY-MM-DD` dates after today in ascending order, at
  most 24 rows) or `withPromissoryNoteCount($count)` (the amount is split evenly,
  due dates from your connection settings; allowed counts are the `noteCount`
  options in `inputFields()`), never both.
- Hosted form: `CreateIntentRequest::withPromissoryNoteSchedule($rows)` fixes the
  plan; without it the buyer chooses the number of notes.
- `cancelPromissoryNotes($paymentId, $serialNumbers = null)` cancels the given
  notes or every open note; `cancelResults()` per note (`CANCELLED`,
  `ALREADY_CLOSED`, `NOT_CANCELLABLE`, `FAILED`) with a Lunixi `errorCode`
  (`PromissoryNoteCancelResult::ERROR_*`: `…not_cancellable`, `…provider_unavailable`
  temporary, `…cancel_rejected` permanent) or null. The payment stays `CAPTURED`.
- `$lunixi->reference()->listProvinces()`, `listDistricts($provinceCode)`,
  `listNeighborhoods($districtCode)` return `[['code' => …, 'name' => …], …]`; an
  empty list means that level is not published yet.
- Webhooks `PromissoryNoteEvents::PAID|PROTESTED|CANCELLED`
  (`payment.promissory_note.*`); read them with `PromissoryNoteEvent::fromEvent($event)`.
- Errors: a malformed schedule is 400 `VALIDATION_ERROR` from the gateway (the SDK
  stops it first); plan and field rules are 400 `payment.method.block_invalid`
  (`details.errors[] = { field, reason }`; an intent schedule with another pinned
  method: `not_allowed_for_<METHOD>`),
  422 `payment.provider.not_available`, 404 `payment.promissory_note.not_found`,
  503 `payment.promissory_note.document_unavailable`.

### Direct card payments

A card is charged from your server with `directMethodPayment()` and
`DirectMethodPaymentRequest::withCard()` (`paymentMethod: CARD`). The former
`chargeCard()` / `chargeCard2d()` / `chargeCard3d()` methods and the direct
2D/3D card endpoints were removed (0.19.0). Direct card payments put the PAN on
your server (PCI DSS SAQ-D); most merchants should use `createIntent()`.

```php
use Lunixi\Sdk\Payment\CardDetails;
use Lunixi\Sdk\Payment\DirectMethodPaymentRequest;

$request = (new DirectMethodPaymentRequest(15000, 'TRY', 'WC-1042'))
    ->withCard(new CardDetails([
        'cardHolderName' => 'Ada Yilmaz',
        'cardNumber' => '5400000000000004',
        'expireMonth' => '12',
        'expireYear' => '28',
        'cvcNumber' => '123',
    ]))                                   // stored card: ['cardUserKey' => …, 'cardToken' => …]
    ->withForce3D(true)                   // leave out to charge without 3D Secure
    ->withInstallment(3, $quoteToken)     // quote from installmentOptions(); the system adds the fee
    ->withCallbackUrl('https://shop.example/lunixi/return')
    ->withBuyer($buyer)
    ->withBillingAddress($billingAddress)
    ->withBasketItems($basketItems);
$result = $lunixi->payments()->directMethodPayment($request, $stableKey);
$redirectUrl = $result->action() !== null ? $result->action()->redirectUrl() : null; // 3D Secure page
```

Migrating from `chargeCard2d(new DirectPaymentRequest($amount, $currency, $orderId, $card, $buyer, $billingAddress, $basketItems))`:
`(new DirectMethodPaymentRequest($amount, $currency, $orderId))->withCard($card)->withBuyer($buyer)->withBillingAddress($billingAddress)->withBasketItems($basketItems)`,
then `directMethodPayment($request, $idempotencyKey)`; for `chargeCard3d` add
`->withForce3D(true)`. The result is a `MethodPaymentResult` instead of the raw
array. A raw-array card body has no replacement: build the request.
`DirectPaymentRequest` stays as the base body of the marketplace 2D/3D charge.

### Payment links

```php
use Lunixi\Sdk\Payment\CreatePaymentLinkRequest;
use Lunixi\Sdk\Payment\PaymentLinkUsage;

// Build the body from your own record, not from the current time: a retry with
// the same Idempotency-Key must send the same body (a different body is 409).
$request = CreatePaymentLinkRequest::fixed(PaymentLinkUsage::SINGLE_USE, 150000, 'TRY', 'Consulting fee')
    ->withExpiresAt($invoice->dueAt())          // DateTimeInterface or ISO-8601, in the future
    ->withReference($invoice->number())
    ->withRecipient(['email' => $invoice->customerEmail()]);

$link = $lunixi->paymentLinks()->create($request, 'plink-' . $invoice->number());
echo $link->url(); // https://pay.lunixi.com/<code>

// Changes create a new version; pass the rowVersion you read.
$lunixi->paymentLinks()->update($link->id(), $link->rowVersion(), ['title' => 'Consulting fee (October)']);
```

The key needs the `payment-link:create`, `:view`, `:manage` and `:send` scopes
and decides the environment (a TEST key sees only TEST links). Fulfil orders
from the `payment.captured` webhook, whose payload carries `paymentLinkId` and
`paymentLinkCode`.

Link images:

```php
use Lunixi\Sdk\Payment\PaymentLinkClient;

$image = $lunixi->paymentLinks()->uploadImage('cover.png', PaymentLinkClient::IMAGE_PNG, file_get_contents('cover.png'));

// The published image's assetId goes on a link (or an item) as imageAssetId.
$lunixi->paymentLinks()->update($link->id(), $link->rowVersion(), ['imageAssetId' => $image['assetId']]);
```

`uploadImage()` asks the gateway for a presigned upload URL, PUTs the file
straight to the storage host over the SDK's HTTP transport (not through
`ApiClient`, so the PUT carries only the upload headers, never the access token
or request signature) and confirms it. Media then checks the stored bytes (PNG,
JPEG or WebP, never SVG; at most 2 MiB and 4096×4096 px). To upload from
somewhere else, run the steps yourself: `createImageUpload()` returns the
`uploadUrl` and `uploadHeaders` to PUT the file with, then call
`confirmImageUpload($assetId)`.

### Wallet

```php
$transfers = $lunixi->wallet()->transfers;

// Amounts are minor units as digit strings: "12550" is 125.50 TRY. A float is
// refused locally; an int is sent as its string.
$transfer = [
    'endUserId' => $endUserId,                 // the end user the money leaves
    'sourceWalletAccountId' => $accountId,     // must be theirs, else 404 WALLET_NOT_FOUND
    'destinationWalletNo' => '5001234567',
    'amount' => '12550',
    'currency' => 'TRY',
];

$quote = $transfers->quoteW2W($transfer);      // fee preview, no Idempotency-Key

// Step 1 reserves the limit (and may send an OTP). Step 2 commits it. Each step
// has its own stable Idempotency-Key: a retry repeats the step, never the money.
$started = $transfers->initiateW2W(
    $transfer + ['scheduleVersionId' => $quote['scheduleVersionId'] ?? null],
    'w2w-' . $transferRef
);
$done = $transfers->completeW2W($started['operationId'], [
    'endUserId' => $endUserId,
    'integrityHash' => $started['integrityHash'],
    'nonce' => $started['nonce'],
    'otpCode' => $otpFromTheEndUser,           // when $started['otpRequired']
], 'w2w-' . $transferRef . '-complete');       // operationId is filled into the body
```

Every wallet route is signed per request, reads included. The API key needs
the `WALLET_SERVICE` product and the wallet scope behind each route (403
`WALLET_INSUFFICIENT_SCOPE` otherwise): `wallet:*:read` for reads;
`wallet:program|enduser|limit|fee:manage` for set-up;
`wallet:operation:intervene` for moving money inside the wallet (W2W,
payments, collect, top-ups); `wallet:bank-payout:operate` for IBAN transfers
and bank withdrawals (GROWTH plan); `wallet:qr:manage`; `wallet:bulk:create`
to create a bulk payout and `wallet:bulk:approve` to submit, approve, process
and retry it (the approving key must differ from the key that created and
submitted the batch); `wallet:enduser:kyc-override` for the
KYC level. Payment requests and collections credit the wallet terminal bound to
the signing key; `businessAccountId` is refused. See
[`samples/08-wallet/`](samples/08-wallet/).

### Bank accounts

```php
use Lunixi\Sdk\BankConnect\BankConnectClient;
use Lunixi\Sdk\BankConnect\BankTransactionDirection;
use Lunixi\Sdk\Exception\ApiException;

$bankConnect = $lunixi->bankConnect();

foreach ($bankConnect->iterateAccounts() as $account) {
    echo $account->ibanMasked(), ' ', $account->freshness()->lastSuccessfulSyncAt(), PHP_EOL;
}

// Newest recorded first; bookedFrom/bookedTo filter on the bank date, at most 92 days apart.
foreach ($bankConnect->iterateTransactions([
    'bookedFrom' => new DateTimeImmutable('-7 days'),
    'direction' => BankTransactionDirection::CREDIT,
]) as $transaction) {
    // amountMinor is an integer in minor units: 150000 = 1,500.00 TRY.
    match_order($transaction->description(), $transaction->amountMinor());
}

try {
    $sync = $bankConnect->requestSync($accountId);   // 202: QUEUED or SCHEDULED (scheduledAt)
} catch (ApiException $e) {
    $wait = BankConnectClient::retryAfterSeconds($e); // 429 RATE_LIMITED: seconds to wait
}
```

The API reads: banks, connections, accounts, balances and transactions.
Connecting a bank and entering bank credentials happen in the Lunixi
dashboard. Every route is signed per request; the key needs the BANK_CONNECT
product, `bank-connect:view` (banks, connections, accounts, requestSync) and
`bank-connect:transactions:view` (balances, transactions), and decides the
environment (a TEST key reads only test-environment accounts). IBANs and
counterparty names are masked. New transactions arrive by the
`bank_account.transaction.received` webhook:

```php
use Lunixi\Sdk\BankConnect\BankAccountTransactionReceived;
use Lunixi\Sdk\BankConnect\BankConnectEvents;

$event = $lunixi->webhooks()->verify($rawBody, $headers, $secret);
if ($event->type() === BankConnectEvents::TRANSACTION_RECEIVED) {
    $transaction = BankAccountTransactionReceived::fromEvent($event); // dedupe on $event->id()
}
```

See [`samples/09-bank-accounts/`](samples/09-bank-accounts/).

### Sanctions screening

```php
use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Sanction\RiskDomain;
use Lunixi\Sdk\Sanction\ScreenNameRequest;
use Lunixi\Sdk\Sanction\SubjectType;

try {
    $result = $lunixi->sanction()->screenName(
        (new ScreenNameRequest('Ayla Kurgu'))
            ->withSubjectType(SubjectType::PERSON)
            ->withCountry('TR')
            ->withRiskDomains([RiskDomain::SANCTIONS, RiskDomain::PEP])
            ->withCustomerReference('cust_84213')
    );
    if ($result->isClear()) {
        // no match AND coverageStatus COMPLETE
    } else {
        // matches (each with all its lists), or PARTIAL / DEGRADED coverage: review
    }
    $audit = [$result->screeningRequestId(), $result->publicationId(), $result->evidenceRef()];
} catch (ApiException $e) {
    // 503 sanction.screen_name.failed: no list publication, the screening did NOT run.
}
```

`ScreenResult::requestId()` is the HTTP request's id; the screening's id is
`screeningRequestId()`. A wallet result keeps `publicationHits()` (listed
parties holding the address) apart from `hits()` (your own list and crypto
intelligence). A procurement-ban result stays not clear while the Turkish KİK
list is in `uncoveredSources()`.

### Insurance

`$lunixi->insurance()` sells and services insurance for your basket items
(`/api/v1/insurance`, product INSURANCE_SERVICE). Every call is signed; the key
decides the environment. Amounts are integers in minor units.

Collecting the premium in your own Lunixi payment:

```php
use Lunixi\Sdk\Insurance\InsuranceClient;
use Lunixi\Sdk\Payment\BasketItem;
use Lunixi\Sdk\Payment\CreateIntentRequest;
use Lunixi\Sdk\Payment\InsuranceItemCategory;

$insurance = $lunixi->insurance();
$ticket = new BasketItem([
    'id' => 'ticket-1', 'price' => 100000, 'name' => 'Izmir-Ankara train ticket', 'category1' => 'ticket',
    'itemType' => BasketItem::TYPE_VIRTUAL, 'insuranceCategory' => InsuranceItemCategory::TICKET,
    'insuranceTypes' => ['TICKET_CANCELLATION'],
]);

// 1. Eligibility: an empty list means insurance is not offered.
$products = $insurance->searchProducts(['currency' => 'TRY', 'context' => ['items' => [$ticket]]]);

// 2. Quote with the same items and the type's fields ($products[0]->fields() lists them).
$quote = $insurance->createQuote([
    'insuranceType' => 'TICKET_CANCELLATION', 'currency' => 'TRY', 'merchantOrderId' => 'ORD-1042',
    'context' => ['items' => [$ticket]], 'insured' => $insured, 'tickets' => $tickets, // from your checkout form
]);
$option = $quote->options()[0];

// 3. The buyer accepts the option's terms.
$order = $insurance->createOrder([
    'quoteId' => $option->quoteId(),
    'consent' => InsuranceClient::consentFromQuote($option, new DateTimeImmutable()),
], 'ins-order-ORD-1042');

// 4. Pay the insured items plus the premium item; amount is the sum.
$premium = InsuranceClient::premiumBasketItem($order, 'Ticket cancellation insurance', 'insurance');
$intent = $lunixi->payments()->createIntent(
    (new CreateIntentRequest(100000 + $order->premiumAmount(), 'TRY', 'ORD-1042'))
        ->withBasketItems([$ticket, $premium]) /* buyer, addresses … */,
    'checkout-ORD-1042'
);

// 5. Bind the order to the payment. The policy is requested once the payment is captured.
$insurance->bindPayment($order->orderId(), $intent->paymentId(), 'ins-bind-ORD-1042');
```

- The basket of a quote is either a payment's recorded basket
  (`paymentIntentId`) or the items you will send in the payment
  (`context.items`); both is a `ConfigurationException`. To collect the
  premium in a Lunixi payment, quote with `context.items`.
- Insurance data on a basket item (`insuranceData`): what the quote needs that
  only you know (`insured`, the type object such as `tickets` — one object for
  the item, no `basketItemId`), so the buyer is not asked again. Partial data is
  allowed. It is personal data, stored encrypted by Lunixi for the quote only.
  `BasketItem` checks its shape before sending. `listProducts()` with the payment
  then reads `prefill()`, `providedFields()` (masked), `quoteReady()` and
  `insuranceDataIssues()`; with `quoteReady()`, `createQuote()` needs only
  `insuranceType`, `currency` and `paymentIntentId`
  (`samples/10-insurance/checkout-with-insurance-data.php`).
- Insurance sold without a basket item: `listProducts(['currency' => 'TRY'])`
  without `paymentIntentId` lists those types, and `createQuote()` with
  neither `paymentIntentId` nor `context` prices one (the insured value is in
  the type object). A type sold only with a basket item answers that quote
  with 412 `insurance.type.not_available`.
- `InsuranceQuoteOption::underwriter()` is the insurance company
  (`InsuranceUnderwriter`: code, name, logo, disclosure text) apart from
  `provider()`; the same company from two providers comes as two options with
  the same code. `InsuranceQuoteSet::excludedOptions()` lists the companies the
  request could not be priced with (`errorCode()`, `errors()` field paths);
  only they are left out. In `InsuranceProduct::fields()` a field may carry
  `requiredAt` and `requiredBy` when only some companies need it.
- The type's own fields (`insured` and the type object named by
  `InsuranceProduct::typeObject()`: `tickets`, `stay`, `device`, `product`,
  `vehicle`, `trip`, `rental`, `instrument`, `event`, `accident`, `procedure`,
  `pet`, `residence`, `abroadStay`) are passed through as given.
  Policy-stage fields (e.g. PNR, ticket number, the insured's e-mail and phone)
  are sent with the quote. A rule violation is a 422
  `insurance.validation.invalid` whose `getResponseBody()['details']['errors']`
  lists every `{field, reason}` at once.
- `premiumBasketItem()` builds `{id: orderId, itemType: VIRTUAL, price:
  premiumAmount}` for an order whose premium you collect
  (`premiumCollectedBy` MERCHANT); a PROVIDER order has no premium item.
- Premium collected outside Lunixi: `confirmPayment($orderId, ['externalPaymentId',
  'paidAmount', 'paidCurrency', 'paidAt'], $key)`, then `issuePolicy($orderId, $key)`.
- `cancelOrder()` cancels the issued policies (asynchronous, CANCEL_PENDING);
  it does not refund. `refundPremium($orderId, [], $key)` refunds from the
  Lunixi payment; `['externalRefund' => ['externalRefundId' => …, 'refundedAt' => …]]`
  records your own refund.
- `createOrder`, `confirmPayment`, `issuePolicy`, `cancelOrder` and
  `refundPremium` require an `Idempotency-Key` (8–200 printable ASCII
  characters, no spaces); `createQuote` and `bindPayment` take one optionally.
- `listReferenceValues($kind)` reads the Lunixi codes a type field takes
  (`InsuranceReferenceKind`, e.g. `OCCUPATION`, `MEDICAL_PROCEDURE_COVER`,
  `PET_BREED`). `DISTRICT`, `DEVICE_MODEL`, `VEHICLE_MODEL`, `BANK_BRANCH` and
  `PET_BREED` need `parentCode` (province, brand, bank, `CAT` | `DOG`).
- `getPolicyDocument($policyId)` returns the PDF; `getPolicyReceipt($policyId)`
  and `getPolicyCertificate($policyId)` return the provider's receipt and
  certificate the same way, only for products that issue them (otherwise 412
  `insurance.document.not_supported`); `exportReconciliation(['from'
  => …, 'to' => …])` returns the CSV with `truncated()` and `nextCursor()` for
  files over 20000 orders. `getReconciliationSummary()` and
  `listReconciliationOrders()` read the same report as objects.
- Webhooks (`insurance.*`): `InsuranceEvents::POLICY_ISSUED`, `POLICY_FAILED`,
  `REFUND_REQUIRED`, `POLICY_DOCUMENT_READY`, `POLICY_CANCELLED`,
  `REFUND_COMPLETED` (`InsuranceEvents::ORDER_EVENTS`); read the payload with
  `InsuranceWebhookData::fromEvent($event)` and dedupe on `$event->id()`.
  `LEAD_SUBMITTED` (`insurance.lead.submitted`, `InsuranceEvents::LEAD_EVENTS`)
  carries a lead summary instead: `InsuranceLeadWebhookData::fromEvent($event)->lead()`
  (`leadId()`, `insuranceType()`, `status()`, no personal data).
- The provider collecting the premium itself (`premiumCollectedBy` PROVIDER)
  cannot be completed through the API yet.

#### Leads

Some insurance types are sold by request only: the provider gives no quote, so
there is no premium, order, policy or document. `createLead()` sends the
request to the provider with your own connection, in the call:

```php
$lead = $insurance->createLead([
    'insuranceType' => 'MOTOR_COMPREHENSIVE',
    'policyholder' => ['identityType' => 'TCKN', 'identityNumber' => $identityNumber, 'birthDate' => $birthDate, 'email' => $email, 'phone' => $phone],
    'vehicle' => ['plate' => $plate, 'registrationDocument' => ['serial' => $serial, 'number' => $number]],
    'note' => 'Call in the afternoon',
], 'lead-ORD-1042');
// $lead->status(): SUBMITTED (the provider received it) or UNKNOWN (not known; it is not sent again)
```

- The fields follow the type's lead schema (`policyholder`, the type object,
  optional `note` up to 1000 characters): `MOTOR_THIRD_PARTY_LIABILITY` and
  `MOTOR_COMPREHENSIVE` take `vehicle`; `CARGO_TRANSIT` takes `shipment`, the
  carrying `vehicle` and an optional `company` (tax identity). A lead has no
  amount, currency, basket item or payment (`InsuranceClient::LEAD_FIELDS`).
- The `Idempotency-Key` is required; the same key with the same body returns
  the same lead. A provider refusal is 422 `insurance.lead.rejected`; a type or
  connection without leads is 412 `insurance.type.not_available`.
- `listLeads(['insuranceType' => …, 'status' => …])` / `iterateLeads()` and
  `getLead($leadId)` read your leads (`InsuranceLead`); `requestMasked()` shows
  the lead as sent with the personal fields and the note masked.
  `InsuranceLeadStatus`: `PENDING`, `SUBMITTED`, `UNKNOWN`, `FAILED` (`errorCode()`).

See [`samples/10-insurance/`](samples/10-insurance/).

## Develop

```bash
composer install
composer test     # phpunit
composer lint     # php -l on src/
```

## Gateway contract

The request signing this SDK implements is documented in
[REST without an SDK](https://docs.lunixi.com/getting-started/sdks#rest-without-an-sdk).
