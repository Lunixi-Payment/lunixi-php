# Native commerce integration

`Lunixi\Sdk\Commerce` is the shared payment workflow for native platform adapters. It uses the existing SDK transport, authentication and payment clients. It does not collect card data. Enabled payment methods, installments, insurance and split availability belong to the merchant's Lunixi form settings; a store adapter must not silently replace these settings.

## Installation and durable storage

Use `lunixi/php-sdk` 0.29 or later. ZIP modules bundle a scoped copy so a merchant does not need Composer or a conflicting site-wide vendor tree. A source repository release does not imply that a package registry or platform marketplace has accepted the module.

Create a separate, backed-up 32-byte encryption key outside the web root. Do not derive it from a webhook secret, merchant private key, database password or public platform identifier. Configure an HTTPS gateway origin only from an operator-approved allowlist. Production defaults to `https://api-gateway.lunixi.com`; test/live access is determined by the merchant API key.

```php
use Lunixi\Sdk\Commerce\{CheckoutService, Money, OrderSnapshot, PdoLedgerStore, SecretBox};

// Use the SAME connection as the native order writes.
$ledger = new PdoLedgerStore($platformPdo, 'lunixi_');
$ledger->install(); // installation/migration only
$service = new CheckoutService($lunixi, $ledger, new SecretBox($serverEncryptionKey));
$order = new OrderSnapshot($configuredMerchantId, 'store:order:123', Money::minor('19.90'), 'TRY');
$session = $service->start('store:order:123', $order, 'https://shop.example/lunixi/return');
```

`Money::minor` accepts decimal **strings**, rejects fractional cents and scientific notation, and never multiplies a float. Apply native tax rounding before conversion. Use exponent 0 for JPY and 3 for KWD; the platform currency precision must agree with the gateway currency. The gateway safe integer limit is enforced. Never recalculate platform totals from browser input.

`PdoLedgerStore` supports MySQL/InnoDB and SQLite. Native platforms that do not expose PDO implement `LedgerStore` on their own connection: serialize an order across processes, commit the reservation before network I/O, roll back on exceptions, and enforce a unique merchant/event inbox key. A PHP session or process-local set is insufficient. Installation must verify transactional order tables; if a platform uses nontransactional tables, implement a durable outbox and idempotent native application instead of claiming atomic writes.

## Checkout rendering

Uncertain create and financial operation retries stop after 23 hours, before the gateway's 24-hour HTTP replay-cache retention. The original key and financial history remain stored. Missing/future operation timestamps also fail closed. Continue with authenticated GET reconciliation and reviewed recovery; never generate a new key to bypass this fence. A confirmed operation can still be read/reconciled. Keep clocks synchronized.

For embedded checkout, load only the fixed official bundle `https://cdn.lunixi.com/lunixi-checkout.min.js`, provide an element with id `lunixi-checkout-form`, and pass the JSON from `$session->browserConfig()` to `Lunixi.checkout.create`. Call `checkout.mount('#lunixi-checkout-form')`. Use the platform's asset and script APIs; preserve CSP nonces where required. Do not print `checkoutFormContent` as HTML. Only a browser token is sent to the client; merchant secrets remain on the server.

For hosted checkout, pass `true` as the fourth argument to `start` and redirect to `$session->hostedUrl()`. The service binds the form to `https://pay.lunixi.com`. Always verify order ownership before showing a token or redirecting. The form's available methods are managed in Lunixi; no duplicate payment-method switches are necessary in the plugin.

The fifth `start` argument enables preauthorization. An authorized order is on hold, not paid. The sixth argument selects the form locale. Callback URLs must use HTTPS. Callback submissions are informational: ignore their payment status and use authenticated reconciliation.

## Webhook and order application

Read raw request bytes once, enforce a request-size limit, and forward case-insensitive headers:

```php
$event = $service->verifyWebhook($rawBody, $headers, $webhookSecret);
// Use event order metadata only to locate a candidate native order.
// reconcile compares the event payment ID against the durable attempt,
// then checks merchant/order/currency/amount using authenticated GET.
$decision = $service->reconcile($nativeOrderKey, function ($decision) use ($platformOrders) {
    $platformOrders->applyLunixiDecision($decision);
}, $event);
```

The native `apply` callback runs inside the ledger transaction and must use that same database connection. It must be idempotent by payment ID and avoid duplicate invoices, stock deductions, credit notes or fulfillment. Queue notifications in a durable outbox and deliver them after commit. Return a successful webhook response only after commit; transient failures return a retryable status without leaking exception payloads. Do not log raw request bodies, browser tokens, private keys, buyer data or authorization headers.

Lock and compare the current native order's merchant, amount and currency with the reserved snapshot before applying a decision. A later admin edit must not allow an older intent to pay the edited order. A native fraud/manual fulfillment hold must remain in force even when funds are captured.

If the create-intent response is lost, a verified payment webhook may locate a candidate reservation by the signed `data.orderId`. `CheckoutService::eventPaymentId($event)` reads the gateway's `intentId`/compatible aliases and rejects conflicts; it is a lookup helper, not payment proof. `reconcile` can bind an empty payment ID only after authenticated GET matches the snapshot, order fingerprint and opaque reservation hash echoed in metadata. Binding, inbox consumption and native order application commit together. Browser returns cannot perform this recovery and an existing bound ID is never replaced.

Fresh v2 signatures and signed event id/type are mandatory. The service claims an event in the same transaction as the order change. A failed order update rolls both back. Replayed deliveries and delayed status reads cannot downgrade a captured/refunded order. Unrecognized or partially captured states require review and never trigger fulfillment. Schedule reconciliation for pending/review attempts so a missed webhook does not strand an order.

Final charged amounts may include a buyer-selected installment fee. An increase above the base order total is accepted only if the authenticated intent echoes the persisted order fingerprint. Store the gross charged amount separately from the platform basket total. Do not add that fee to the basket a second time. Split success requires the correct parent, matching order/currency, balanced totals, complete aggregate capture and no compensation/reconciliation requirement. A captured leg alone never completes an order. Split functionality also requires the corresponding gateway/form rollout and merchant eligibility.

## Capture, refunds and retries

```php
// Native admin route must check its permission and CSRF token first.
$service->mutate($orderKey, 'native-refund:456', 'refund', 990, $applyDecision);
$service->mutate($orderKey, 'native-capture:789', 'capture', 1990, $applyDecision);
$service->mutate($orderKey, 'native-void:890', 'void', null, $applyDecision);
```

Persist native operation IDs before calling the service. Reuse the same ID and exact amount after timeouts. A reused ID with different parameters is rejected. A mutation response alone does not update the order: the service reads authenticated settlement balances afterward. Split refunds use the aggregate refund endpoint and may require manual reconciliation for nonrefundable methods. Refunds must never be marked successful merely because an HTTP request returned 2xx.

Read `$service->operationStatus($orderKey, $nativeOperationId)` before displaying a successful admin result. Its `state` is `pending`, `uncertain` or `confirmed`; no idempotency key is exposed. An asynchronous 2xx answer remains pending until an authenticated balance change confirms the requested capture/refund, or a verified release confirms a void. While an operation is unresolved, new operation IDs are blocked; retry the existing ID. Later webhook or scheduled reconciliation can confirm it. `PaymentDecision` exposes exact `capturedAmount`, `refundedAmount` and `gatewayStatus`. A `partially_captured` decision remains on hold and never fulfills the order; subsequent capture is limited to the remaining authorization and refunds to the actually captured balance.

`resetFailedAttempt($orderKey)` permits another checkout only after an authenticated terminal failure with no captured funds. It preserves prior payment identity in the ledger history. Expired browser tokens, timeouts, unknown outcomes and pending split legs do not authorize another charge. Keep credentials and immutable attempt snapshots available until reconciliation and refund retention periods end; uninstall must not silently destroy financial history.

## Verification

`vendor/bin/phpunit tests/Commerce` exercises exact money boundaries, identity mismatch, incomplete split captures, fee correlation, timeout/key reuse, encrypted storage, atomic rollback, replay consumption, changed order rejection, stable refund retries and delayed observations. Native adapters additionally need real platform installation, checkout, permissions, order transitions, refund and concurrency tests. These shared tests do not replace native platform verification.
