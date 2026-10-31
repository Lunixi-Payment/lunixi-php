# Changelog

All notable changes to `lunixi/php-sdk`.

## 0.29.0

### Added

- Shared `Commerce` checkout orchestration for native store adapters: exact decimal money conversion, immutable order snapshots, durable attempt/operation keys, authenticated payment and split-parent reconciliation, and signed webhook envelope binding.
- Atomic order update and webhook inbox contract (`LedgerStore`), with a MySQL/InnoDB and SQLite implementation (`PdoLedgerStore`). Reservations commit before network calls; unknown results never rotate financial operation keys.
- Authenticated encryption for stored credentials and checkout tokens, fixed-origin hosted checkout URLs, and script-safe browser configuration without rendering gateway HTML.
- Capture, refund and void operations with durable platform operation IDs. New checkout attempts require an authenticated terminal failure. See [COMMERCE.md](COMMERCE.md) for adapter responsibilities and installation.

## 0.28.1

### Fixed

- `SanctionClient::listMonitoringMatches()` / `listBlacklistEntries()` (and
  their `iterate…` forms): `nextCursor()` read the cursor only inside `data`,
  but these lists send `pageInfo` beside `data`, so it was `null` and
  iteration stopped after the first page. The cursor is now read from there
  too, in the same order as the Node SDK.

## 0.28.0

Insurance data on a basket item. Additive: no class, method or field was
removed. The gateway and the Checkout Form that prefills and prices it are live.

### Added

- `BasketItem` accepts `insuranceData` (it was dropped before): the root
  fields of the insurance type's quote body (`policyholder`, `insured`, one
  type object such as `tickets` or `device`). Checked before sending: an array
  keyed by root, known roots only (`BasketItem::INSURANCE_DATA_PERSON_ROOTS`,
  `INSURANCE_DATA_TYPE_ROOTS`), `insured` a list of 1–10 objects, the other
  roots one object, no `basketItemId` in a type object, no nested `null`, at
  most 16 KB as JSON (`INSURANCE_DATA_MAX_BYTES`); `insuranceCategory` is
  required with it. A `null` root, or an empty array, counts as not sent.
  Messages name the field path, never the value.
- `InsuranceProduct::prefill()`, `providedFields()` (`path`, `masked`),
  `quoteReady()` and `insuranceDataIssues()` (`basketItemId`, `field`,
  `reason`).
- Sample `samples/10-insurance/checkout-with-insurance-data.php`.

## 0.27.0

Split payments (one payment funded by stored cards and non-card methods) and
payment options (provider order, a provider turned off only in the checkout
form). Requires the gateway release with split payment legs of other methods;
do not publish this version before it is live.

### Added

- `PaymentClient::createSplitPayment(SplitPaymentRequest, $idempotencyKey)`
  (`POST /payments/split`) with the `SplitPaymentRequest` builder:
  `addCardLeg($cardToken, $amount, $force3D, $installment, $quoteToken)` and
  `addMethodLeg($method, $providerCode, $amount, $fields)` (the
  `direct/payment` block; `providerCode` required). Checked before sending, as
  in the Node SDK. Result `SplitPaymentResult` with `steps()`
  (`SplitPaymentStep`), `pendingStep()` (`SplitPaymentPendingStep`) and
  `expiresAt()`.
- Continuing a split payment after a step is the same call with the same
  request and key. While the payment runs the gateway answers HTTP 400 with the
  split status as `code`; `createSplitPayment()` returns that answer
  (`SplitPaymentStatus::IN_PROGRESS`). A failed split payment still throws
  `ApiException`.
- `getSplitPayment()` (`SplitPaymentDetail`, `SplitPaymentLeg`),
  `listSplitPayments()` (`SplitPaymentList`), `refundSplitPayment()`
  (`SplitRefundResult`).
- `list(['includeLegs' => true])`; `PaymentIntent::split()` (`PaymentSplitRef`).
- `PaymentMethodInfo::splitEligible()`, `splitBehavior()`;
  `PaymentMethodProvider::splitEligible()`, `splitBehavior()`.
- `SplitBehavior`, `SplitPaymentStatus`, `SplitPaymentEvents` (with
  `LEG_EVENTS_SUBSCRIPTION`), `SplitPayErrorCode`, `CheckoutFormErrorCode`.
- Sample `02-payments/split-payment.php`.
- 3D Secure display of the Checkout Form split payment (set in the panel, Form
  Settings → 3D Secure → 3D Secure display): `ThreeDsDisplay` (`INLINE`,
  `REDIRECT`). `SplitPaymentResult::threeDsDisplay()` and
  `SplitPaymentResult::completion()` read the form and continuation page
  answers; both are null on `POST /payments/split`.
- `SplitPaymentResult::paymentId()`: the main (checkout) payment's `paymentId`
  in the form answers (`form/split/init`, `form/split/status`) and the
  continuation page answers; null on `POST /payments/split`.
- `SplitCompletionOutcome` (`success`, `failure`, `pending`): the `status` of
  the signed POST your `callbackUrl` receives when a split payment concludes on
  the hosted continuation page.
- `SplitPayErrorCode`: `RESUME_UNAVAILABLE`, `RESUME_ALREADY_OPENED`,
  `RESUME_PROOF_INVALID`, `RESUME_NOT_CONFIGURED`,
  `THREE_DS_RESTART_EXHAUSTED`, `THREE_DS_RESTART_EXPIRED`
  (`split_pay.three_ds.restart_expired`, HTTP 410: the card's 3D Secure session
  is closed or has expired, the buyer is asked to enter the card again;
  `details.reason` `THREE_DS_CLOSED` or `THREE_DS_SESSION_EXPIRED`).
- `SplitPayErrorCode::INSURANCE_REQUIRES_CARD_LEG`
  (`split_pay.insurance.requires_card_leg`, HTTP 422): insurance is selected
  in the Checkout Form but the split payment has no card leg. The premium is
  collected from a card leg, and card legs of an insured split payment are
  single-payment (no installments).

### Changed

- Faturana Yansıt examples and the sample send the `providerCode` your buyer
  picked. `providerSelectionMode()` is documented as the API behaviour; in the
  Checkout Form the buyer always picks the provider.
- `listPaymentMethods()` documents the order (form settings) and that a
  provider turned off only in the checkout form stays in the list.
- README: parçalı ödeme örnekleri (split payment examples): multiple cards,
  multiple payment methods (several cards with a wallet and Faturana Yansıt),
  reading the steps, completing the pending step (action confirm) and handling
  `split_pay.*` errors, with links to the docs pages.

## 0.26.0

Additive: no public class, method or constant was removed.

### Added

- **Insurance leads** for the types sold by request only (no quote, premium,
  order or policy): `InsuranceClient::createLead($request, $idempotencyKey)`
  (HTTP 201, key required), `listLeads(['limit', 'cursor', 'insuranceType',
  'status'])` / `iterateLeads()`, `getLead($leadId)`, returning `InsuranceLead`
  (`leadId()`, `insuranceType()`, `schemaVersion()`, `status()`, `errorCode()`,
  `requestMasked()`, `submittedAt()`, `createdAt()`, `updatedAt()`).
  `InsuranceLeadStatus` (`PENDING`, `SUBMITTED`, `UNKNOWN`, `FAILED`);
  `InsuranceClient::LEAD_FIELDS`, `LEAD_NOTE_MAX_LENGTH`,
  `FILTERS['listLeads']`. The lead `note` is sent as given, at most 1000
  characters.
- `InsuranceEvents::LEAD_SUBMITTED` (`insurance.lead.submitted`) and the
  lists `ORDER_EVENTS` and `LEAD_EVENTS`; `InsuranceLeadWebhookData::fromEvent()`
  with `lead()` (`InsuranceWebhookLead`).
- `InsuranceClient::getPolicyReceipt()` and `getPolicyCertificate()`: the
  provider's receipt and certificate PDF of a policy, for products that issue
  them.
- `createQuote()` accepts the type objects `pet` (`PET_HEALTH`), `residence`
  (`FOREIGN_RESIDENT_HEALTH`) and `abroadStay` (`ABROAD_STAY_HEALTH`).

### Changed

- `InsuranceEvents::ALL` also lists `insurance.lead.submitted`.
  `InsuranceWebhookData::fromEvent()` reads the order events only
  (`ORDER_EVENTS`) and refuses a lead event; read that one with
  `InsuranceLeadWebhookData::fromEvent()`.

## 0.25.0

Additive: no public class, method or constant was removed.

### Added

- **Insurance sold without a basket item.** `InsuranceClient::listProducts()`
  without `paymentIntentId` lists the insurance types sold on their own
  (`basketItemIds()` empty), and `createQuote()` with neither `paymentIntentId`
  nor `context` prices one; the insured value is in the type object. Both
  basket sources together is still a `ConfigurationException`.
- **Insurance company of a quote option.** `InsuranceQuoteOption::underwriter()`
  (`InsuranceUnderwriter`: `code()`, `name()`, `logoUrl()`, `disclosureText()`),
  apart from `provider()`; `underwriterName()` stays.
  `InsuranceQuoteSet::excludedOptions()` (`InsuranceExcludedQuoteOption`:
  `underwriter()`, `provider()`, `errorCode()`, `errors()`) lists the companies
  the request could not be priced with. `InsuranceProduct::fields()` documents
  the `requiredAt` / `requiredBy` keys.
- `createQuote()` accepts every type object of the gateway: `stay`, `device`,
  `product`, `vehicle`, `trip`, `rental`, `instrument`, `event`, `accident`,
  `procedure` next to `tickets` (`InsuranceClient::QUOTE_FIELDS`). They were
  refused before sending until now.
- `InsuranceItemCategory`: `PET`, `MICROMOBILITY`, `MUSICAL_INSTRUMENT`,
  `SHIPMENT`, `EDUCATION` (accepted by `BasketItem`).
- `InsuranceReferenceKind`: `VEHICLE_USAGE`, `LIABILITY_LIMIT`, `OCCUPATION`,
  `PET_BREED` (`parentCode` `CAT` | `DOG`), `DWELLING_TYPE`, `DWELLING_FLOOR`,
  `BANK`, `BANK_BRANCH` (`parentCode` the bank), `FINANCE_INSTITUTION`,
  `MEDICAL_PROCEDURE_COVER`.

### Changed

- `listProducts()` requires only `currency`.

## 0.24.0

Additive: no public class, method or constant was removed. `ApiClient::request()`
now also takes a `stdClass` body (sent as a JSON object, `{}` when empty);
existing calls are unchanged.

### Added

- **Insurance** (`$lunixi->insurance()`, `Insurance\InsuranceClient`) —
  `/api/v1/insurance` (product INSURANCE_SERVICE). Every call is signed.
  - `listProducts(['currency', 'paymentIntentId'])` and
    `searchProducts(['currency', 'context' => ['items' => …]])` — insurance types
    offered for a payment's basket or for the items before the payment
    (`InsuranceProduct[]`; an empty list means not offered)
  - `listReferenceValues($kind, $filters)` / `iterateReferenceValues()` — Lunixi
    reference codes (`InsuranceReferenceKind`)
  - `createQuote($request, ?$idempotencyKey)` (HTTP 201, `InsuranceQuoteSet`),
    `getQuote($quoteId)` (`InsuranceQuoteOption`)
  - `createOrder($request, $idempotencyKey)` (HTTP 201), `listOrders()` /
    `iterateOrders()`, `getOrder($orderId)` — `InsuranceOrder`
  - `bindPayment($orderId, $paymentIntentId, ?$idempotencyKey)`,
    `confirmPayment($orderId, $payment, $idempotencyKey)`,
    `issuePolicy($orderId, $idempotencyKey)`,
    `cancelOrder($orderId, $idempotencyKey)`,
    `refundPremium($orderId, ['externalRefund' => …]|[], $idempotencyKey)`
  - `getPolicyDocument($policyId)` — the policy PDF (`InsurancePolicyDocument`)
  - `getReconciliationSummary($filters)`, `listReconciliationOrders($filters)` /
    `iterateReconciliationOrders()`, `exportReconciliation($filters)` — the CSV
    (`InsuranceReconciliationExport`) with `truncated()` / `nextCursor()` for
    files over 20000 orders and `aborted()` for an interrupted file
  - `InsuranceClient::consentFromQuote($option, $acceptedAt)` and
    `InsuranceClient::premiumBasketItem($order, $name, $category1)` — the order
    consent and the VIRTUAL premium basket item (`id` = orderId, `price` =
    premium) of an order whose premium you collect.
- Request fields and filters are checked before sending: an unknown field, a
  quote with both or neither of `paymentIntentId` and `context.items`, a
  missing or malformed `Idempotency-Key` where it is required (8–200 printable
  ASCII characters), a non-integer amount or an unknown reference kind is a
  `ConfigurationException`. The insurance type's own fields are sent as given;
  the gateway reports their errors (422 `insurance.validation.invalid`).
- Response objects with one accessor per gateway field: `InsuranceProduct`,
  `InsuranceReferenceValue`, `InsuranceQuoteSet`, `InsuranceQuoteOption`,
  `InsuranceTerms`, `InsuranceProvider`, `InsuranceOrder`,
  `InsuranceOrderPolicy`, `InsurancePage`, `InsuranceReconciliationSummary`,
  `InsuranceReconciliationGroup`, `InsuranceReconciliationTotals`,
  `InsuranceReconciliationOrder`, `InsuranceReconciliationPolicy`.
- Value sets `InsurancePremiumCollectedBy`, `InsuranceQuoteStatus`,
  `InsuranceOrderStatus`, `InsuranceCollectionStatus`, `InsurancePolicyStatus`,
  `InsuranceRefundStatus`, `InsuranceDocumentStatus`, `InsurancePaymentMode`,
  `InsuranceOrderSurface`, `InsuranceReferenceKind`.
- Webhook: `InsuranceEvents` (`insurance.*`) and
  `InsuranceWebhookData::fromEvent()` with `InsuranceWebhookPolicy`.
- Samples in `samples/10-insurance`.

## 0.23.0

Sanctions screening brought up to the gateway (`/api/v1/sanction/*`): list
publication evidence, coverage, merged matches, the wallet and procurement-ban
result shapes, and a route table shared with the Node SDK. Contains breaking
changes (0.x minor, as in 0.19.0): read "Changed" and "Removed" before
upgrading.

### Fixed

- The screening results read the gateway envelope's `data`. `ScreenResult`
  read `matches` at the response root, where the gateway never puts them, so
  every name screening came back empty and `isClear()` was `true` even with
  matches. `ScreenOutcome` read every field at the root as well.
- `registerMonitoring()` and `addBlacklist()` read their id from `data`.

### Added

- `ScreenNameRequest::withRiskDomains()` (`RiskDomain`; an unknown or empty
  list is refused before sending).
- `ScreenResult`: `screeningRequestId()`, `publicationId()`,
  `publicationRevision()`, `coverageStatus()`, `isCoverageComplete()`,
  `evidenceRef()`, `screenedRiskDomains()`, `uncoveredRiskDomains()`,
  `datasetFreshness()`, `success()`, `raw()`.
- `SanctionMatch`: `entityId()`, `riskDomains()`, `sourceListName()`,
  `lists()` (`SanctionMatchList`: datasetCode, displayName, datasetKind,
  datasetVersionId, subLists, programs, entityIds, listings).
- `WalletScreenResult` with `publicationHits()` (`WalletPublicationHit`:
  listed party, `listedAddress`, `listedChains`, `caseRule`, `lists`) kept
  apart from `hits()` (your own list and crypto intelligence).
- `ProcurementBanScreenResult` (`ProcurementBanHit`, `searchedLists()`,
  `uncoveredSources()`, coverage).
- Pages and new reads: `listScreeningHistory`, `listMonitoringAlerts`,
  `listMonitoredEntities`, `listMonitoringMatches`, `listBlacklistEntries`
  (`SanctionPage`, each with an `iterate…`), `getDatasetFreshness`,
  `searchEntities`, `getEntityProfile` (`screeningRequestId`),
  `getEntityGraph`, `getIndirectExposure`, `getOwnershipAggregation`
  (`entityIds` of a merged match). `registerMonitoring()` takes
  `frequency` / `riskDomainScope` in a fourth `$options` argument.
- `SanctionRoutes` (`ROUTES`, `NESTED_FIELDS`, `VALUE_SETS`), the same table
  as the Node SDK's `SANCTION_ROUTES`. Bodies and filters of these routes are
  checked before sending (unknown field, missing field, no party and no
  account, none of entityName/tckn/vkn, value outside its set →
  `ConfigurationException`).
- `RiskDomain`, `CoverageStatus`, `WalletScreenMode`, `HitType`.

### Changed

- `isClear()` is `true` only for a successful screening with no match and
  `coverageStatus` COMPLETE (`PARTIAL`, `DEGRADED` or no coverage is not
  clear). `ScreenOutcome::isClear()` applies the coverage rule when the answer
  reports coverage (transaction screening); account screening has none.
- `ScreenResult::requestId()` and `ScreenOutcome::requestId()` return the
  envelope's `requestId`, the HTTP request's id. The screening's id is
  `ScreenResult::screeningRequestId()`.
- `screenWallet()` returns `WalletScreenResult` (was `ScreenOutcome`); `$chain`
  is optional. `screenPublicProcurementBan()` returns
  `ProcurementBanScreenResult` (was the raw array).
- A screening answer whose envelope `status` is not `success` throws
  `ApiException` instead of being read.
- `screeningHistory()`, `monitoringAlerts()`, `monitoredEntities()` and
  `listBlacklist()` still return the rows of the first page; they accept
  `cursor` (and `includeTotal`) and refuse an unknown filter.
- `ScreenNameRequest::withThreshold()` refuses a value outside 0–100.

### Removed (breaking)

- `SanctionClient::syncProcurementBans()`. The gateway retired
  `POST /api/v1/sanction/intelligence/public-procurement-ban/sync` (it could
  write back a synthetic row); every call was a 404. Procurement-ban screening
  reads the active list publication; there is no replacement call.

## 0.22.0

Bank transfers and currency exchange (`/api/v1/bank-connect`, product
BANK_CONNECT, scope `bank-connect:transfer`). The API key must have an IP
allowlist; calls from an unlisted IP are refused by the gateway.

### Added

- `BankConnectClient::transfers()` (`BankTransferClient`): `listPurposes()`,
  `create(array $request, ?string $idempotencyKey = null)`, `list()` /
  `iterate()`, `get()`. A transfer created with an API key is `SUBMITTED` at
  once (no e-mail code) and queued for the bank.
- `BankConnectClient::fx()` (`BankFxClient`): `listRates()`, `createQuote()`,
  `createOrder(array $request, ?string $idempotencyKey = null)`,
  `listOrders()` / `iterateOrders()`, `getOrder()`.
- `Idempotency-Key`: a UUID v4 is generated when null; a key you pass is
  checked (8–200 printable ASCII). A network error or a 5xx is retried with
  the same key.
- Bodies and filters are checked before sending against
  `BankTransferRoutes::ROUTES` (the same table as the Node SDK's
  `BANK_TRANSFER_ROUTES`).
- Results `BankTransfer`, `BankTransferCreated`, `BankTransferPurpose`,
  `BankFxRates`, `BankFxRate`, `BankFxQuote`, `BankFxOrder`,
  `BankFxOrderCreated`; value sets `BankTransferStatus`, `BankTransferRail`,
  `BankTransferInitiatedVia`, `BankFxOrderStatus`, `BankFxRequestedSide`;
  webhooks `BankTransferEvents` + `BankTransferEvent::fromEvent()`.
- `BankCapability::OUTGOING_TRANSFER`, `BankCapability::FX_EXCHANGE`.
- `BankConnectConnection::capabilities()`, `supports()`;
  `BankConnectAccount::productCode()`, `displayName()`, `accountType()`;
  `BankConnectBalance::availableWithoutOverdraftMinor()`,
  `overdraftAvailableMinor()`; `BankConnectTransaction::isReversal()`,
  `reversalOfTransactionId()`, `counterpartyBankCode()`,
  `counterpartyBankName()`.

## 0.21.0

Senetle Ödeme (promissory-note payment, `paymentMethod: PROMISSORY_NOTE`).
Requires the gateway release that accepts the `promissoryNote` block; do not
publish this version before it is live.

### Added

- `DirectMethodPaymentRequest::withMethodFields(PaymentMethod::PROMISSORY_NOTE, …)`
  sends the `promissoryNote` block (debtor and guarantor fields from
  `inputFields`). The plan: `withPromissoryNoteSchedule($rows)` or
  `withPromissoryNoteCount($count)`, never both. Checked before sending:
  integer minor-unit amounts adding up to `amount`, `YYYY-MM-DD` calendar dates
  in ascending order, at most 24 rows, note count 1–24 (`PromissoryNotePlan`).
- `CreateIntentRequest::withPromissoryNoteSchedule($rows)`: the plan shown on
  the hosted form.
- `PaymentClient::getPromissoryNotes()`, `cancelPromissoryNotes()`,
  `downloadPromissoryNoteDocument()` (PDF); results `PromissoryNotes`,
  `PromissoryNote`, `PromissoryNoteDocument`.
- `LunixiClient::reference()` (`Reference\ReferenceClient`): `listProvinces()`,
  `listDistricts()`, `listNeighborhoods()`.
- `PromissoryNoteStatus`, `PromissoryNoteCancelResult` (with the Lunixi
  `errorCode` values `ERROR_NOT_CANCELLABLE`, `ERROR_PROVIDER_UNAVAILABLE`,
  `ERROR_CANCEL_REJECTED`), `PromissoryNoteEvents`,
  webhook reader `PromissoryNoteEvent::fromEvent()`.
- `MethodInputField::TYPE_VKN`, `TYPE_ADDRESS`, `TYPE_GROUP`; `requiredWhen()`,
  `itemFields()`, `minItems()`, `maxItems()`.
- `ApiClient::requestBinary()` option `accept`.

### Changed

- Method block and confirm `fields` values may be an associative array of
  scalars (ADDRESS, at most 16 fields) or a list of at most 10 associative
  arrays (GROUP), as the gateway accepts them. Field names `__proto__`,
  `constructor` and `prototype` are refused, as the gateway does.
- `PaymentMethod::METHOD_BLOCK_FIELDS` and `DIRECT_METHOD_PAYMENT_FIELDS`
  include `promissoryNote`.

## 0.20.0

### Added

- `Payment\ProviderSelectionMode` (`BUYER`, `PLATFORM`) and
  `PaymentMethodInfo::providerSelectionMode()` (null when the response does not
  carry the field). `BUYER`: the buyer picks the brand; with more than one
  provider `providerCode` is required. `PLATFORM`: do not ask the buyer and
  leave `providerCode` null; the platform picks a connected provider before any
  money moves.
- `PaymentClient::listPaymentMethods(?string $paymentMethod = null)` sends
  `GET /api/v1/payments/methods?paymentMethod=<code>` and lists that method only
  (empty when it is not open; `QR` is sent as `QR_CODE`). A code that is not
  upper case throws `ConfigurationException` before sending; an unknown code is
  a 400 `payment.method.unknown`. Without the argument the call is unchanged.
- 422 `payment.provider.none_eligible` (`ApiException`) on
  `directMethodPayment()`: a `PLATFORM` method without `providerCode` and no
  connected provider could take the payment; nothing was charged
  (`details.candidates[]`: `providerCode`, `outcome`, `reason`).

### Changed

- `ProviderSelection::OPTIONAL` also covers a `PLATFORM` method with more than
  one provider connected. The value set is unchanged and
  `requiresProviderCode()` still tells when `providerCode` must be sent.

## 0.19.0

### Removed (breaking)

The gateway no longer has the `POST /api/v1/payments/direct/2d` and
`/direct/3d` endpoints. A card is charged from the server only through
`POST /api/v1/payments/direct/payment` with `paymentMethod: CARD`
(`force3D: true` for 3D Secure).

- `PaymentClient::chargeCard()`, `chargeCard2d()` and `chargeCard3d()` were removed.
- `DirectPaymentRequest` stays: it is the base body of the marketplace split
  charge (`MarketplaceClient::chargeMarketplaceCard2d()` / `3d()`,
  `POST /api/v1/payments/marketplace/2d|3d`), which is unchanged.
- `createIntent()`, `createMarketplaceIntent()`, the marketplace client,
  `capture()`, `refund()` and `void()` are unchanged.

### Migration

```php
// before
$response = $lunixi->payments()->chargeCard3d(
    (new DirectPaymentRequest($amount, 'TRY', $orderId, $card, $buyer, $billingAddress, $basketItems))
        ->withInstallment(3, $quoteToken)
        ->withCallbackUrl($callbackUrl),
    $idempotencyKey
);

// after
$result = $lunixi->payments()->directMethodPayment(
    (new DirectMethodPaymentRequest($amount, 'TRY', $orderId))
        ->withCard($card)                 // new card, or cardUserKey + cardToken for a stored card
        ->withForce3D(true)               // leave out for chargeCard2d
        ->withInstallment(3, $quoteToken)
        ->withCallbackUrl($callbackUrl)
        ->withBuyer($buyer)
        ->withBillingAddress($billingAddress)
        ->withBasketItems($basketItems),
    $idempotencyKey
);
$redirectUrl = $result->action() !== null ? $result->action()->redirectUrl() : null;
```

- The result is a `MethodPaymentResult` (`paymentId()`, `status()`,
  `action()`), not the raw array. For 3D Secure the gateway answers HTTP 202
  with a `REDIRECT` action; send the buyer to `action()->redirectUrl()`. A
  declined payment is HTTP 400 and throws `ApiException`.
- `withPreauth`, `withDescription`, `withCustomerId`, `withPaymentChannel`,
  `withPaymentGroup`, `withMetadata` keep their names.
- A raw-array card body (`chargeCard(array …)`) has no replacement; build a
  `DirectMethodPaymentRequest`.
- Samples: `02-payments/direct-2d-payment.php` and `direct-3d-payment.php` were
  replaced by `direct-method-card-payment.php`; `03-cards/charge-stored-card-2d.php`
  uses `directMethodPayment()`.

## 0.18.0

### Breaking

The gateway has one amount field: `amount`. `price` and `paidPrice` were removed
from the intent, marketplace, direct 2D/3D and direct/payment bodies; a body that
still sends them is answered with 400 `payment.amount.field_removed`. Use this
version together with that gateway release: an older SDK sending `paidPrice` gets
the same 400.

- `Payment\DirectPaymentRequest::__construct(int $amount, …)`: the first argument
  is now sent as `amount` (was `paidPrice`). `withPrice()` was removed. The
  marketplace 2D/3D charge (`chargeMarketplaceCard2d()` / `3d()`) sends the same
  body.
- `Payment\DirectMethodPaymentRequest::__construct(int $amount, …)`: the first
  argument is now sent as `amount` (was `paidPrice`, with `price` copied from it).
  `withPrice()` was removed.
- `Payment\CreateIntentRequest`: `withPrice()`, `withPaidPrice()` and
  `withInstallment()` were removed. The intent has no installment field (the
  gateway answers it with 400); the buyer picks the installment on the hosted
  form.
- `Payment\PaymentMethod::DIRECT_METHOD_PAYMENT_FIELDS` lists `amount` instead of
  `price` and `paidPrice`.
- A raw array with `price` or `paidPrice` given to `PaymentClient::chargeCard()`,
  `PaymentClient::createMarketplaceIntent()` or
  `MarketplaceClient::chargeMarketplaceCard()` throws `ConfigurationException`
  before sending. A raw direct card array must carry `amount`.

### Added

- `PaymentClient::REMOVED_AMOUNT_FIELDS` (`['price', 'paidPrice']`).

### Fixed

- `MarketplaceClient::chargeMarketplaceCard()` documents the real body:
  `amount` and `sellers[]{ subDealerId, amountMinor, scenario? }`.

## 0.17.0

Additive: no public class, method or constant was removed or changed.

### Added

- **Payment methods** — payments with any payment method from the merchant
  server, besides the card endpoints:
  - `PaymentClient::directMethodPayment(DirectMethodPaymentRequest, $idempotencyKey)`
    — `POST /api/v1/payments/direct/payment`, Idempotency-Key required.
  - `PaymentClient::confirmPaymentAction($paymentId, $actionId, PaymentActionConfirmRequest)`
    and `resendPaymentAction($paymentId, $actionId)` — the action endpoint.
  - `PaymentClient::listPaymentMethods()` — `GET /api/v1/payments/methods`,
    returned as `PaymentMethodList` → `PaymentMethodInfo` →
    `PaymentMethodProvider` → `MethodInputField`.
  - `Payment\DirectMethodPaymentRequest`: `withMethodFields($method, $providerCode, $fields)`,
    `withCard()`, `withForce3D()`, `withInstallment()`, `withPreauth()`,
    `withCallbackUrl()` and the order fields of the direct 2D/3D body.
  - `Payment\PaymentActionConfirmRequest`: `otp()`, `fields()`, `consent()`,
    `withConsentAccepted()`.
  - `Payment\MethodPaymentResult` and `Payment\PaymentAction` (OTP target,
    consent, redirect URL, INPUT fields, buyer charge breakdown).
  - `Payment\PaymentMethod` (codes, block names, DTO field lists),
    `Payment\PaymentActionType`, `Payment\ProviderSelection`.
  - `PaymentIntent::paymentMethod()` and `PaymentIntent::action()`.
- Requests are checked before sending: card-only fields with another method, a
  malformed method block, a method the gateway has no block for yet and a
  missing Idempotency-Key throw `ConfigurationException`.
- Samples `02-payments/list-payment-methods.php`,
  `02-payments/mobile-billing-otp-payment.php`.

### Unchanged

- `chargeCard2d()`, `chargeCard3d()`, `createIntent()`, `capture()` and `void()`
  keep their endpoints and behaviour.

## 0.16.0

### Added

- **Pre-authorization as a typed field** — `withPreauth(bool $preauth)` on
  `Payment\CreateIntentRequest` (hosted checkout and the marketplace intent) and
  `Payment\DirectPaymentRequest` (direct 2D/3D and the marketplace 2D/3D charge).
  `true` holds the amount and the payment becomes `AUTHORIZED`; take it with
  `capture()` or release it with `void()`. The value goes into the body's
  `preauth` field.
- `MarketplaceClient::chargeMarketplaceCard2d()` / `chargeMarketplaceCard3d()` —
  build the marketplace direct charge from a `DirectPaymentRequest` and
  `MarketplaceSeller` legs, so `withPreauth()` reaches
  `POST /api/v1/payments/marketplace/2d|3d` as well. The raw-array
  `chargeMarketplaceCard()` stays.
- A raw direct payload (`chargeCard()`, `chargeMarketplaceCard()`) whose `preauth`
  is not a boolean is refused with `ConfigurationException` before anything is
  sent.

### Deprecated

- `CreateIntentRequest::withAutoCapture(bool $autoCapture)`. It still works and
  is now `withPreauth(!$autoCapture)`: it writes the typed `preauth` field and no
  longer writes `metadata.autoCapture`. Your other `metadata` keys are left as
  they are. Move to `withPreauth()`; the method will be removed in a later
  release.

### Upgrade note

- Pre-authorization through the request `metadata` is no longer supported by
  this SDK. If you build `metadata` with an `autoCapture` key yourself, replace
  it with `withPreauth()`: `autoCapture: false` → `withPreauth(true)`.

## 0.15.0

### Added

- **Basket item insurance fields**: `BasketItem` now copies `insuranceCategory`,
  `insuranceTypes`, `insuranceEligible`, `insuranceValueAmount` and
  `insuranceContext` (before this release it dropped them). They are optional
  and accepted by `createIntent` (Checkout Form) only; direct 2D/3D payments
  answer them with 400. They are checked before sending, the way the gateway
  checks them: a category outside `InsuranceItemCategory`, `insuranceTypes`
  that is not a list of at most 10 unique type codes, a non-boolean
  `insuranceEligible`, an `insuranceValueAmount` below 1 or not an integer, an
  `insuranceContext` that is not keyed by field name, or `insuranceTypes` /
  `insuranceValueAmount` / `insuranceContext` without `insuranceCategory` is a
  `ConfigurationException`. A null value or an empty `insuranceContext` is not
  sent. An item without insurance fields is sent exactly as before.
- `InsuranceItemCategory` constants (`TICKET`, `HOTEL`, `EVENT`, `DEVICE`,
  `ELECTRONICS`, `VEHICLE`, `TRAVEL`, `HOME`, `HEALTH`, `ALL`) and
  `BasketItem::ITEM_TYPES`, `BasketItem::INSURANCE_TYPE_PATTERN`,
  `BasketItem::MAX_INSURANCE_TYPES`.

### Changed

- `BasketItem` rejects an `itemType` other than `PHYSICAL` or `VIRTUAL` with a
  `ConfigurationException`. The gateway answers such a value with 400 on every
  endpoint, so the request could not succeed before either.

## 0.14.0

Additive: no public class, method or constant was removed or changed, so `^0.13`
constraints keep resolving and no integration needs a change.

### Added

- **Bank accounts** — `$lunixi->bankConnect()` (`BankConnect\BankConnectClient`)
  for `/api/v1/bank-connect` (product BANK_CONNECT): read your connected bank
  accounts, their balances and transactions.
  - `listBanks()` / `iterateBanks()` — the catalogue, with `availability` and
    `capabilities`.
  - `listConnections()` / `iterateConnections()`, `getConnection($id)`.
  - `listAccounts()` / `iterateAccounts()`, `getAccount($id)` — masked IBAN and
    account number, and `freshness()` (`lastSuccessfulSyncAt`,
    `nextSyncAvailableAt`, `minSyncIntervalSeconds`).
  - `listBalances()` / `iterateBalances()` — the latest balance of each account.
  - `listTransactions()` / `iterateTransactions()`, `getTransaction($id)` —
    newest recorded first (`receivedAt`); filters `accountId`, `bookedFrom` /
    `bookedTo` (at most 92 days apart), `direction`, `minAmountMinor` /
    `maxAmountMinor`, `canonicalType`.
  - `requestSync($accountId)` — asks for a fresh read (HTTP 202, `QUEUED` or
    `SCHEDULED`); `BankConnectClient::retryAfterSeconds()` reads the wait from
    a 429 `RATE_LIMITED`.
  - Every call is signed per request (Ed25519), reads included. Scopes:
    `bank-connect:view` (banks, connections, accounts, requestSync) and
    `bank-connect:transactions:view` (balances, transactions).
  - Filters are checked before anything is sent: an unknown filter, a value
    outside its set, a limit outside 1–100, more than 100 account ids, a
    negative or non-integer amount, `minAmountMinor` above `maxAmountMinor`,
    `bookedTo` without `bookedFrom` or a window over 92 days is a
    `ConfigurationException`.
  - Results: `BankConnectPage`, `BankConnectBank`, `BankConnectBankRef`,
    `BankConnectConnection`, `BankConnectAccount`, `BankConnectFreshness`,
    `BankConnectBalance`, `BankConnectTransaction`, `BankConnectSyncRequest`.
    A field the gateway leaves out reads as null; amounts are integers in
    minor units.
  - Constants: `BankConnectionStatus`, `BankAccountStatus`,
    `BankTransactionDirection`, `BankTransactionType`, `BankAvailability`,
    `BankCapability`, `BankSyncStatus`.
- **`bank_account.transaction.received`** webhook: `BankConnectEvents` and
  `BankAccountTransactionReceived::fromEvent($event)`.
- Samples in `samples/09-bank-accounts/`.

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
