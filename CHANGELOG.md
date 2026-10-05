# Changelog

All notable changes to `lunixi/php-sdk`.

## 0.13.0

Additive: no public class, method or constant was removed or changed, so `^0.12`
constraints keep resolving and no integration needs a change.

### Added

- **Wallet** — `$lunixi->wallet()` (`Wallet\WalletClient`) for the closed-loop
  wallet's merchant surface, `/api/v1/wallet/*` (60 routes, the same table and
  method names as the Node SDK's `client.wallet`).
  - `ping()` and thirteen sub-clients as public properties: `programs`,
    `endUsers`, `wallets`, `deposits`, `corporate`, `fees`, `transfers`,
    `withdrawals`, `operations`, `topups`, `payments`, `qr`, `bulkPayouts` —
    e.g. `$lunixi->wallet()->transfers->completeW2W($operationId, $request, $idempotencyKey)`.
  - `Wallet\WalletRoutes` — every route's method, path, Idempotency-Key flag
    and accepted body/query fields, plus the nested row specs of employee
    import/offboard and bulk payout items. Requests are checked against it
    before anything is sent, because the gateway rejects unknown body keys
    (`forbidNonWhitelisted`): an unknown or missing field, an amount that is
    not a minor-unit digit string (`"12550"` = 125.50 TRY; a float is refused,
    a non-negative int is sent as its string), a missing or malformed
    Idempotency-Key (1–128 characters, trimmed) or an unknown filter is a
    `ConfigurationException`.
  - Every wallet call is signed per request (Ed25519 step-up), reads included:
    the routes sit behind SignatureGuard.
  - Two-step money movement: `initiate*` then `complete*` for W2W, W2IBAN and
    bank withdrawals; `complete*` fills `operationId` into the body from the
    path argument and refuses a different one.
  - Lists return `Wallet\WalletPage` (`items()`, `nextCursor()`, `hasMore()`,
    `raw()`), reading both `{items, nextCursor}` and `{operations, nextCursor}`;
    `endUsers`, `corporate`, `operations` and `qr` have `iterate()`.
  - `qr->image()` returns `Wallet\WalletQrImage` (SVG or PNG bytes).
  - Constants: `WalletAccountType`, `WalletEndUserStatus`, `WalletKycLevel`,
    `WalletOtpChannel`, `WalletQrType`, `WalletQrImageFormat`,
    `WalletBulkTargetType`, `WalletCorporateAccountStatus`.
  - The key needs the `WALLET_SERVICE` product and the wallet scopes listed on
    `WalletClient` (403 `WALLET_INSUFFICIENT_SCOPE` otherwise); bulk payouts
    are four-eyes (`wallet:bulk:create` creates; `wallet:bulk:approve`
    submits, approves, processes and retries; the approving key must differ
    from the key that created and submitted the batch).
- Samples in `samples/08-wallet/`.

## 0.12.0

Additive: no public class, method or constant was removed or changed, so `^0.11`
constraints keep resolving and no integration needs a change.

### Added

- **Fraud event ingest** — `$lunixi->fraud()->events` (`Fraud\FraudEventsClient`).
  The Node SDK had this surface and PHP did not, so a PHP integration could ask
  for a decision but could not feed the history that counting rules read.
  - `record(array $event, ?string $idempotencyKey = null): FraudEventResult`
    → `POST /api/v1/fraud/events/transactions`
  - `recordBatch(array $events, ?string $idempotencyKey = null): FraudEventBatchResult`
    → `POST /api/v1/fraud/events/transactions/batch`
  - Both routes are signed **per request** (Ed25519 step-up), not bearer-only
    like the decision surface: the signature is what binds the event to the
    account, and `merchantId` is stamped from the key, never read from the body.
  - `FraudEventResult::deduplicated()` — retrying with the same `eventId` is
    safe; the second call is deduplicated and writes nothing.
  - `FraudEventBatchResult::failures()` — a batch answers `200` and keeps writing
    the remaining events when one fails validation, so `failed()` and
    `failures()` are the fields to check rather than the HTTP status.
  - The batch limit (500) is enforced locally as well, because the gateway
    rejects the WHOLE batch above it.
- **`fraud.report.ready`** event type (`FraudEvents::REPORT_READY`). The platform
  catalogue publishes seven `FRAUD_SERVICE` event types and this class carried
  six. The event carries NO download link.

## 0.11.1

Additive: no public class, method or constant was removed or changed, so
`^0.11` constraints (the WordPress plugin's included) take this release.

### Added

- **Payment links** — `$lunixi->paymentLinks()` (`Payment\PaymentLinkClient`) for
  `/api/v1/payments/links`:
  - `create(CreatePaymentLinkRequest, $idempotencyKey)` — the key is required
    (8–200 printable ASCII characters). The same key with the same body returns
    the stored link; `PaymentLink::wasReplayed()` tells you so. The same key
    with a different body is 409 `payment_link.idempotency.conflict`.
  - `list($filters)` / `iterate($filters)` — cursor pages, newest first.
    `PaymentLinkList` reads `pageInfo` beside `data` (`hasMore()`,
    `nextCursor()`, `totalCount()`).
  - `get()`, `update($id, $expectedRowVersion, $changes)` — only the keys you
    pass change, `null` clears a field; `usage`, `amountMode` and `currency`
    are fixed at creation and refused locally.
  - `pause()` / `resume()` / `deactivate()`, `send()` (e-mail or SMS; the
    Idempotency-Key is required so a retry cannot message twice), `qr()`
    (`PaymentLinkQrCode` with the SVG/PNG bytes), `listAttempts()` /
    `listPayments()` (+ `iterate…`).
  - Link images: `uploadImage($fileName, $contentType, $bytes)` uploads a
    PNG, JPEG or WebP image (`IMAGE_PNG`, `IMAGE_JPEG`, `IMAGE_WEBP`) and
    returns the published image, whose `assetId` is what `withImageAssetId()`
    takes. The steps are available one by one too: `createImageUpload()`
    (`POST /images`, the presigned upload URL) and
    `confirmImageUpload($assetId)` (`POST /images/{assetId}/confirm`), which
    returns only a published image.
- Builders `CreatePaymentLinkRequest` (`fixed()`, `open()`, `itemized()`),
  `PaymentLinkItem`, `PaymentLinkCustomField`; read model `PaymentLink`; value
  sets `PaymentLinkUsage`, `PaymentLinkAmountMode`, `PaymentLinkState`,
  `PaymentLinkAttemptState`. Closed value sets and the keys of `buyerFields`,
  `recipient` and `prefill` are checked locally, so a typo fails with its name
  instead of as a 400.
- `ApiClient::requestBinary()` for endpoints that answer with bytes.
- Samples under `samples/07-payment-links/`.

### Fixed

- **Signed request bodies containing U+2028 / U+2029 failed with
  `INVALID_DIGEST`.** The gateway recomputes the `Digest` over
  `JSON.stringify` of the parsed body, which leaves these two characters
  unescaped; PHP's `json_encode` escaped them, so the hashes differed. Bodies are
  now encoded with `JSON_UNESCAPED_LINE_TERMINATORS`. This affected every
  step-up call carrying free text (descriptions, addresses) pasted from a
  source that uses those separators.
- **Signed request bodies with integer-like object keys failed with
  `INVALID_DIGEST`.** When the gateway re-serialises the parsed body, keys such
  as `"2024"` or `"10"` come first, in ascending order (JavaScript object key
  order); PHP kept them where they were inserted, so a body like
  `metadata: {"crmId": …, "2024": …}` hashed differently on each side. Request
  bodies are now sent in the gateway's key order. Bodies without such keys are
  sent byte for byte as before.

### Notes

- Payment-link routes accept the API-key signature only: every call, reads
  included, is sent step-up (signed). The key needs the `payment-link:*`
  scopes; keys created before payment links do not have them.
- The key decides the environment: a TEST key creates and sees only TEST links.
- The image upload's PUT goes straight to the storage host behind the
  presigned URL over the SDK's HTTP transport (the one `LunixiClient` was given,
  e.g. the WordPress adapter), not through `ApiClient`: it carries only the
  upload headers, never the access token or the request signature. Both image
  routes need the `payment-link:create` scope. SVG is not accepted, and an
  image declared over 2 MiB is refused before anything is uploaded.

## 0.11.0

### Fixed

- **🔴 `Fraud\FraudClient` never reached the gateway.** The base path was `/fraud`,
  but the gateway serves the fraud surface under `/api/v1/fraud` — every fraud
  call returned **404**. Measured against the live gateway:

  ```
  POST /fraud/decisions/evaluate         → 404
  POST /api/v1/fraud/decisions/evaluate  → 401   (route exists, needs auth)
  ```

  Every other client in this package already used `/api/v1/…`; only fraud had
  drifted. If you integrated fraud through this SDK, it has not been working —
  upgrade and the same calls will start reaching the service.

  The tests did not catch it because they asserted `endsWith('/fraud/…')`, which
  a missing prefix still satisfies. They now assert the full path.

### BREAKING

- **`Fraud\FraudClient::blacklists()` and `Fraud\BlacklistClient` removed.**
  They called a panel-only route that is not part of the customer API surface —
  the same reason the intelligence methods were removed in 0.10.0. Lists are
  managed in the fraud console.

  These calls returned 404 in every released version, so no working integration
  is affected.

### Migration

```php
// before — 404 in every released version
$fraud->blacklists()->create(BlacklistClient::TYPE_EMAIL, 'bad@x.com');

// after — manage lists in the fraud console; no API equivalent today
```

If you need list management from your own systems, ask us: it needs a customer
endpoint that does not exist yet, and we size that work on request.

## 0.10.0

### BREAKING

- **`Fraud\FraudEvents` constants replaced.** `DECISION_COMPLETED`,
  `SHADOW_REQUESTED` and `SHADOW_COMPLETED` are removed. Those three values
  (`fraud.decision.completed`, `fraud.shadow.requested`, `fraud.shadow.completed`)
  were internal event-bus topic names, not webhook event types — no merchant
  endpoint could ever receive them, so subscribing to them silently never fired.

  The class now carries the six fraud webhook event types a merchant endpoint can
  actually subscribe to: `FLOW_ASYNC_COMPLETED`, `FLOW_ACTION_TRIGGERED`,
  `QUOTA_THRESHOLD_REACHED`, `ALERT_TRIGGERED`, `SERVICE_DEGRADED`,
  `SERVICE_RECOVERED`. `FraudEvents::all()` returns them in catalogue order.

- **`Fraud\FraudClient` intelligence methods removed:** `deviceProfiles()`,
  `ipProfiles()` and `identityProfiles()`. These call panel-only routes that are
  not part of the customer API surface; they are read in the fraud panel instead.

### Fixed

- **`Fraud\FraudClient::decisionLogs()` now supports `cursor`.** The endpoint is
  cursor-paginated; the client previously sent only `limit`, so no caller could
  reach the second page.
- **`Fraud\FraudDecisionList` now exposes paging.** `hasMore()`, `nextCursor()`
  and `totalCount()` read `pageInfo`, which is a **sibling** of `data` in the
  gateway envelope, not nested inside it. The object previously discarded it.

### Migration

```php
// before — never delivered
if ($event->type() === FraudEvents::DECISION_COMPLETED) { … }

// after — subscribe to the async flow result instead
if ($event->type() === FraudEvents::FLOW_ASYNC_COMPLETED) { … }
```

```php
// paging through decision logs
$cursor = null;
do {
    $page = $lunixi->fraud()->decisionLogs(array_filter([
        'limit'  => 100,
        'cursor' => $cursor,
    ]));
    foreach ($page->items() as $decision) { … }
    $cursor = $page->nextCursor();
} while ($page->hasMore());
```

## 0.9.0

### BREAKING

- `Fraud\EvaluateRequest::withIntegrationMode()` removed — the standalone decision
  endpoint accepts only `standalone`, and any other value is rejected with 400.
