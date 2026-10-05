# Lunixi PHP SDK

Framework-agnostic PHP client for the Lunixi payment gateway. Used standalone
(`composer require lunixi/php-sdk`) or bundled — namespace-scoped via PHP-Scoper —
inside the WordPress/WooCommerce, OpenCart and other Lunixi integrations.

All money/auth/webhook logic lives here once; the platform plugins are thin
adapters over this SDK (see `../STANDARDS.md`).

- **Requires:** PHP 7.4+, `ext-sodium`, `ext-curl`, `ext-json`
- **License:** MIT

## Status

Payment SDK v1 implemented & verified (**248 unit tests, 1833 assertions**, PHP 8.4):

| Component | Purpose |
|---|---|
| `Configuration` | Immutable, validated config (base URL, key id, Ed25519 key, env, retries). |
| `Auth\CanonicalRequest` | Builds the exact canonical string the gateway signs. |
| `Auth\Ed25519Signer` | Signs requests (libsodium); accepts the dashboard PKCS#8 PEM; derives the public key; can generate keypairs (onboarding). |
| `Auth\TokenManager` + `TokenStore*` | Signed `/auth/token` exchange → cached bearer; durable store injectable (WP transient). |
| `Http\HttpClientInterface` + `CurlHttpClient` + `HttpResponse` | Swappable transport (the WP plugin injects a `wp_remote_request` adapter). |
| `ApiClient` | Authenticated requests: bearer + Ed25519 step-up + Idempotency-Key + money-safe retry (401 re-auth; 5xx/network retried ONLY when idempotent). |
| `Payment\PaymentClient` | `createIntent` / direct 2D-3D / BIN-installments / stored cards / `capture` / `refund` / `void` / `get` / `list` against the verified gateway routes. |
| `Payment\PaymentLinkClient` | Payment links (`/api/v1/payments/links`): create, list/iterate, get, update, pause/resume/deactivate, send, QR, attempts, payments, image upload. Every gateway call is signed (the routes accept the API-key signature only); the image PUT to storage carries no credentials. |
| `Payment\*` (DTOs) | `CreateIntentRequest` (current CreatePaymentIntentDto fields) + `DirectPaymentRequest`/`CardDetails`/`InstallmentOptionsRequest`/`StoreCardRequest` + `Buyer`/`Address`/`BasketItem`; result objects `CheckoutIntent`/`PaymentIntent`/`PaymentList`; `PaymentStatus`/`Currency` constants. Payment links: `CreatePaymentLinkRequest`/`PaymentLinkItem`/`PaymentLinkCustomField`, `PaymentLink`/`PaymentLinkList`/`PaymentLinkQrCode`, `PaymentLinkUsage`/`PaymentLinkAmountMode`/`PaymentLinkState`/`PaymentLinkAttemptState`. |
| `Wallet\WalletClient` | Closed-loop wallet (`/api/v1/wallet/*`, 60 routes): `ping()` plus sub-clients `programs`, `endUsers`, `wallets`, `deposits`, `corporate`, `fees`, `transfers`, `withdrawals`, `operations`, `topups`, `payments`, `qr`, `bulkPayouts`. Every call is signed; requests are checked against `WalletRoutes` (unknown/missing field, non-digit amount, missing Idempotency-Key) before anything is sent. Lists return `WalletPage`; the QR image `WalletQrImage`; constants in `WalletAccountType`, `WalletKycLevel`, `WalletOtpChannel`, `WalletQrType`, `WalletQrImageFormat`, `WalletBulkTargetType`, `WalletEndUserStatus`, `WalletCorporateAccountStatus`. |
| `Webhook\WebhookVerifier` + `WebhookEvent` | Verifies inbound webhooks fail-closed: v2 = HMAC-SHA256 over the exact raw body (`v2=` header items; during a secret rotation any one matching item is enough). |
| `Exception\*` | Typed error hierarchy. |
| `LunixiClient` | Facade: `->payments()`, `->paymentLinks()`, `->wallet()`, `->webhooks()`, `->api()`, `->tokens()`, `->signer()`. |

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
    (new CreateIntentRequest(1000, 'TRY', 'WC-1042'))->withInstallment(3)
);
$token = $intent->token(); // → embedded JS SDK renders/submits the form

// 2) Admin actions (step-up + Idempotency-Key handled for you).
$lunixi->payments()->capture('pi_123', 500, $stableKey);   // partial capture
$lunixi->payments()->refund('pi_123', 300, 'return', $stableKey);
$payment = $lunixi->payments()->get('pi_123');             // reconciliation
if ($payment->isCaptured()) { /* … */ }
```

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

## Develop

```bash
composer install
composer test     # phpunit
composer lint     # php -l on src/
```

## Gateway contract

The exact, code-verified auth/webhook contract this SDK implements is documented
in [`../GATEWAY_CONTRACT.md`](../GATEWAY_CONTRACT.md).
