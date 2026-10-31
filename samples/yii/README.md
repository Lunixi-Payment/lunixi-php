<p align="center"><img src="web/assets/lunixi.svg" width="240" alt="Lunixi"></p>

# Yii 2 checkout example

[English](README.md) · [Türkçe](README.tr.md) · [Guide](https://docs.lunixi.com/en/sdks/yii) · [Source](https://github.com/Lunixi-Payment/lunixi-php/tree/main/samples/yii)

A runnable **Yii 2.0.55 / PHP 8.2+** web/console application using **`lunixi/php-sdk:^0.29.5`**. Native `IdentityInterface` session login, CSRF and owned-order checkout connect to hosted or embedded Lunixi form. Exact raw webhooks and authenticated settlement update native SQLite orders, event inbox and encrypted outbox atomically. This is a source example in the PHP SDK repository, not a separately published Yii extension.

Two explicit demo accounts own separate server-side 19.90 TRY orders. Browser amounts are never authoritative; the module collects no card data and enables no implicit mock payment. Methods, split cards, installments and insurance stay configured in Lunixi. Authentication/signing, integer money, split-parent proof and safe financial retries remain in the shared SDK.

## Run locally

Use 64-bit PHP 8.2+, Composer, PDO SQLite, Sodium, cURL and JSON. The official Yii basic template's BSD-3-Clause attribution is preserved. Unused mail/debug/generator/demo routes are removed. This plain HTML application does not use Bower/jQuery assets; Composer replacement follows Yii's official documented no-assets installation option.

```bash
composer install
cp env.example .env
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

Fill this example's ignored `.env` **shell environment file**, then explicitly load it. No parent sample environment is read:

| Variable | Value |
| --- | --- |
| `LUNIXI_MERCHANT_ID` | Registered merchant identity |
| `LUNIXI_ENVIRONMENT` | `TEST` or `LIVE`, matching key and webhook connection |
| `LUNIXI_KEY_ID`, `LUNIXI_PRIVATE_KEY` | Registered server-side Ed25519 credentials |
| `LUNIXI_WEBHOOK_SECRET` | Merchant webhook signing secret |
| `LUNIXI_ENCRYPTION_KEY` | Independent base64-encoded 32-byte key from the first command |
| `YII_COOKIE_KEY` | A different independent cookie-validation key from the second command |
| `LUNIXI_APP_URL` | Public HTTPS base URL for callbacks |
| `LUNIXI_CHECKOUT_MODE` | `embedded` (default) or optional `hosted` |
| `LUNIXI_PREAUTH` | `true` for authorization first, otherwise `false` |
| `DEMO_ALICE_PASSWORD`, `DEMO_BOB_PASSWORD` | Explicit strong passwords of at least 16 characters |
| `APP_LANGUAGE` | `en` or `tr` |

```bash
set -a
. ./.env
set +a
php yii lunixi/setup
php yii serve --port=8081
```

Open `http://localhost:8081/login`, sign in as `alice@example.test` or `bob@example.test` using your supplied password. Only the explicit console setup creates tables/accounts/orders. The private database defaults to `runtime/lunixi.sqlite` outside `web/`; `LUNIXI_DATABASE` can select another private SQLite file. Keep the encryption/cookie keys separate and stable across upgrades; never commit secrets, SQLite files, session files or logs.

The example shell file enables **loopback-only development HTTP** with `APP_ENV=dev`, `YII_LOCAL_HTTP=1`. Real TEST checkout needs a public HTTPS server/TLS tunnel and a registered **`https://your-host/webhooks/lunixi`** connection. Set `LUNIXI_APP_URL` accordingly. For deployment point the document root to `web/`, use `APP_ENV=prod`, `YII_LOCAL_HTTP=0`, secure session cookies, HTTPS, trusted host/proxy policy and debug off. The encryption key must not be derived from cookie, private-key, webhook or DB secrets.

## Native boundaries

| Route | Requirement |
| --- | --- |
| `GET/POST /login` | Native CSRF, password hash, atomic SQL login rate limit |
| `POST /logout` | Native authenticated session + CSRF |
| `GET /orders` | Current user's server-side orders only |
| `POST /payments/{id}` | Native AccessControl, VerbFilter, CSRF and ownership |
| `GET /payments/{id}/return` | Owned read only; submitted status/amount/ID are ignored |
| `POST /payments/{id}/return` | Stateless informational POST; fixed same-origin 303 to owned GET |
| `POST /webhooks/lunixi` | Dedicated raw-body v2 signed webhook |

Strict URL parsing is enabled. Only the dedicated webhook POST and exact informational return POST disable CSRF. The return adapter accepts at most 64 KiB, ignores all submitted fields and performs no session/order lookup, database write or gateway request. Unknown orders receive the same fixed relative 303 without disclosure. A cross-site POST may omit SameSite=Lax cookies; the following GET retains native authentication and ownership. Do not verify a return form with the webhook secret. Also enforce body limits at the reverse proxy; PHP can buffer input before the application check. Checkout/login/logout retain Yii's native CSRF check. Session IDs rotate on login; identity resolves from native SQL, not user-supplied customer IDs. No card collection/recurring/token-vault endpoint is exposed.

The approved API origin is fixed at `https://api-gateway.lunixi.com`; actual environment comes from the key, without an invented environment field. The default embedded mode opens the form on your own website. Optional hosted mode redirects to the exact validated `paymentPageUrl` returned by the backend and encrypted in the durable attempt; it never builds a URL from the browser token. Embedded mode mounts browser-safe JSON using only `https://cdn.lunixi.com/lunixi-checkout.min.js`; gateway HTML is never injected. Configure CSP for the official form origins.

## Durable payment and accounting

The SDK reserves before network I/O; browser tokens and paid outbox payloads are authenticated-encrypted using the separate key. Timeouts retry the same request/key. Unknown results never authorize automatic key replacement. SDK 0.29.3 conservatively fences uncertain financial POST replay at **23 hours**, before the gateway's 24-hour idempotency-cache expiry. Authenticated GET/webhook reconciliation remains available after that fence. A fresh signed event and the opaque original attempt/fingerprint can recover a lost create response.

Webhooks verify bounded exact raw bytes, v2 HMAC, timestamp, signed ID/type/environment, durable replay and payload collision identity, then authenticated GET payment evidence. Merchant/order/payment/currency/amount and complete split-parent aggregate proof must match. Signed status alone and browser return fields never mark paid.

`NativeLedger` uses the exact native `yii\db\Connection` and its transaction/savepoint counters. Its first SQLite write locks before reads; an outer/nested native/PDO transaction is rejected. Native order, inbox and encrypted outbox writes commit together and a SQL-trigger failure rolls all back. The current native order is compared again under that serialized transaction; owner/merchant/environment/base totals/currency changes or cancellation require review.

Authorization and partial capture stay on hold. `FAILED` without definite outcome proof remains review with no fresh key. Native order principal stays separate from gross charged/captured/refunded amounts when installments add fees. A paid outbox event is inserted once only after complete authenticated capture. Nothing automatically deletes financial history.

## Reconciliation and business effects

```bash
php yii lunixi/reconcile
```

Schedule authenticated reconciliation to recover missed webhooks. It prints error counts without secrets and returns a nonzero exit status on errors. Retain attempts, inbox, credentials and independent encryption key through reconciliation/refund retention.

The example records orders and an encrypted `order.paid` outbox; fulfillment/email/item-tax/refund-note rules belong to your application. Connect a durable **postcommit** consumer, rechecking current order/hold before dispatch and holding uncertain external results for review. Financial admin operations can use the SDK's durable capture/refund/void API with actual application operation IDs; no unsafe public admin route or fake domain-specific bookkeeping is included.

This runnable database adapter supports **SQLite PDO only**. Implement/test another native `LedgerStore` against your production database/order transaction API before changing drivers; do not swap in process memory or an independent PDO transaction.

## Verification

```bash
php vendor/bin/phpunit -c phpunit.xml
python3 tests/http-smoke.py
php vendor/bin/phpstan analyse -c phpstan.neon --no-progress
php vendor/bin/phpcs --standard=phpcs.xml
composer audit
```

Tests boot actual Yii web routing, native security/identity and SQLite, using deterministic injected gateway HTTP responses. Actual cryptography/persistence are preserved. The separate real HTTP server smoke checks native cookies/session rotation, CSRF, owner/verb gates, anonymous cross-site POST → 303 → protected GET and forged returns without sending a gateway payment request. Cases include SQL-trigger rollback/retry, event replay/tamper/environment, exact money, unknown create/key reuse, signed opaque recovery, retention fence and FAILED review. No live Lunixi charge is created.

Complete a registered TEST-merchant hosted/embedded checkout and authentic webhook against your HTTPS application before deployment. Production identity policy, native business side effects and higher-volume database acceptance remain your application's integration work. See [SDK security](../../SECURITY.md).

Primary references: [Yii installation](https://www.yiiframework.com/doc/guide/2.0/en/start-installation), [authentication](https://www.yiiframework.com/doc/guide/2.0/en/security-authentication), [CSRF/security](https://www.yiiframework.com/doc/guide/2.0/en/security-best-practices), [native database transactions](https://www.yiiframework.com/doc/guide/2.0/en/db-dao), [current release](https://www.yiiframework.com/download?version=2.0).
