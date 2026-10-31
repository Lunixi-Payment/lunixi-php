<p align="center"><img src="public/assets/lunixi.svg" width="240" alt="Lunixi"></p>

# CodeIgniter 4 checkout example

[English](README.md) · [Türkçe](README.tr.md) · [Guide](https://docs.lunixi.com/en/sdks/codeigniter) · [Source](https://github.com/Lunixi-Payment/lunixi-php/tree/main/samples/codeigniter)

A runnable **CodeIgniter 4.7.5 / PHP 8.2+** application using **`lunixi/php-sdk:^0.29.3`**. It has native session login, CSRF-protected owned-order checkout, hosted or embedded Lunixi form, a raw signed webhook endpoint, native SQLite transactions and an encrypted durable payment outbox. It is a framework example inside the existing PHP SDK repository, not a separate package/bundle.

The two explicit demo accounts each own a server-side 19.90 TRY order. No browser amount is accepted, no raw card data is collected and no API/mock payment is enabled automatically. Payment methods, split cards, installments and insurance stay managed in Lunixi's form settings. The shared SDK owns signing, payment evidence, split settlement, token encryption and safe retry rules.

## Run locally

Requirements: 64-bit PHP 8.2+, Composer, Intl, Mbstring, Sodium, cURL, JSON and native SQLite3. CodeIgniter's official App Starter 4.7.5 is included as the application skeleton; keep its MIT attribution.

```bash
composer install
cp .env.example .env
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
```

Edit this example's **own** ignored `.env` (it does not load a parent sample's environment):

| Variable | Value |
| --- | --- |
| `LUNIXI_MERCHANT_ID` | Registered merchant identity |
| `LUNIXI_ENVIRONMENT` | `TEST` or `LIVE`, matching the actual key/webhook connection |
| `LUNIXI_KEY_ID`, `LUNIXI_PRIVATE_KEY` | Registered server-side signing credentials |
| `LUNIXI_WEBHOOK_SECRET` | Merchant webhook secret |
| `LUNIXI_ENCRYPTION_KEY` | Independent base64-encoded 32-byte key generated above |
| `LUNIXI_APP_URL` | Your public **HTTPS** base URL for return/webhook registration |
| `LUNIXI_CHECKOUT_MODE` | `hosted` (default) or `embedded` |
| `LUNIXI_PREAUTH` | `true` to authorize first; otherwise `false` |
| `DEMO_ALICE_PASSWORD`, `DEMO_BOB_PASSWORD` | Explicit strong passwords, at least 16 characters each |

Keep the encryption key separate from credentials, database and backups; retain it across restarts/upgrades. Never derive it from a private/webhook/cookie secret. Do not commit `.env`, keys, SQLite files or runtime logs.

```bash
php spark lunixi:setup
php spark serve --host 127.0.0.1 --port 8080
```

Open `http://localhost:8080/login`, sign in as `alice@example.test` or `bob@example.test` with the password you supplied. Their authoritative orders are created only by the explicit setup command; no HTTP route performs DDL. The file database defaults to `writable/lunixi.sqlite` outside `public/`; `LUNIXI_DATABASE` can select another private SQLite file.

Use a TLS tunnel or an HTTPS server for real TEST checkout, set `app.baseURL` and `LUNIXI_APP_URL` accordingly, and register **`https://your-host/webhooks/lunixi`** in Lunixi. A loopback HTTP session is useful for local UI work; the SDK still requires HTTPS callback URLs. For deployment point the web root to `public/`, enable HTTPS/secure session cookies (`app.forceGlobalSecureRequests=true`, `cookie.secure=true`), keep debug off and apply the production host/proxy policy.

## Routes and payment authority

| Route | Native boundary |
| --- | --- |
| `GET/POST /login` | Native session, stored password hash, CSRF and native throttling |
| `POST /logout` | Authenticated session + CSRF |
| `GET /orders` | Only the current user's server-side orders |
| `POST /payments/{id}` | Session + CSRF + ownership before the gateway request |
| `GET /payments/{id}/return` | Owned read only; ignores submitted status/amount/payment ID |
| `POST /payments/{id}/return` | Stateless informational callback; fixed same-origin 303 to the owned GET |
| `POST /webhooks/lunixi` | Signed server-to-server raw body |

The exact webhook POST and informational return POST are the only CSRF exemptions. The return adapter accepts at most 64 KiB, ignores every submitted field and performs no session/order lookup, database write or gateway call. It redirects even for an unknown order without disclosing it. A cross-site form POST can omit SameSite=Lax cookies; its fixed 303 allows the following GET to use the normal authenticated session and ownership check. Return form signatures are not verified with the webhook secret. Apply request-body limits at the reverse proxy too; PHP may buffer input before the application check.

Auto-routing is disabled. The gateway origin is fixed at `https://api-gateway.lunixi.com`; the actual key selects TEST/LIVE access, without an invented environment request parameter. Hosted redirects use `https://pay.lunixi.com/payment/<token>`. Embedded mode mounts browser-safe JSON using only the official `https://cdn.lunixi.com/lunixi-checkout.min.js`; it never renders gateway HTML. Permit the documented form origins in your CSP.

The native order stores immutable merchant/environment/base amount/currency/form settings. The SDK reserves a stable attempt before network I/O and encrypts browser tokens with SecretBox. A timeout reuses the same request/key; unknown older attempts are **never rotated automatically**. The gateway's idempotency cache is 24 hours; SDK 0.29.3 uses a conservative **23-hour replay fence**. Expired uncertain attempts require authenticated GET/webhook reconciliation, with no new financial POST. Raw signed opaque attempt metadata can recover a lost create response.

The webhook verifies exact bounded raw-byte v2 HMAC, timestamp, signed ID/type/environment and replay/payload collision identity, then authenticates a payment GET. Merchant/order/payment/currency/amount and complete split-parent settlement must match. The native current order is compared again inside the same transaction before application. Browser fields or a signed status alone cannot mark paid.

`NativeLedger` uses CodeIgniter's **same SQLite3 connection/transaction depth** for order, inbox and outbox writes; it does not start an independent PDO transaction. Its first write acquires SQLite's write lock before reads, avoiding deferred lock upgrades. Native SQL errors roll everything back; CI's strict transaction status is reset only after the adapter's rollback finishes. Outer/nested transactions are rejected.

Authorization and partial capture remain on hold. `FAILED` without definitive outcome proof stays review, without a new key. Cancellation, changed order identity and merchant/environment changes also require review. Base amount stays separate from gross captured/refunded balances when installment fees apply. `order.paid` is queued once in an encrypted SQL outbox only after complete authenticated capture.

## Reconciliation and application effects

```bash
php spark lunixi:reconcile
```

Schedule this command to recover missed webhooks. It performs authenticated reads and returns a nonzero status on errors without printing secrets. It never interprets a browser return as payment evidence or silently deletes financial history. Retain attempts, event inbox and encryption keys through your reconciliation/refund period.

This application persists native orders and paid-event outbox records; it does not invent fulfillment/email/tax rules for your application. Connect a durable postcommit outbox consumer to your business workflow, rechecking the current order/hold before dispatch and holding uncertain external outcomes for review. Capture/refund/void interfaces can use the SDK's durable operation API with real application operation IDs; they are not exposed as unauthenticated sample routes.

The runnable store is intentionally **SQLite3 only**. For another production database implement and test its native `LedgerStore` against the same order connection/transaction API; do not substitute process memory or wrap it in an outer transaction.

## Verification

```bash
php vendor/bin/phpunit -c phpunit.xml
python3 tests/http-smoke.py
php vendor/bin/phpstan analyse -c phpstan.neon --no-progress
php vendor/bin/phpcs --standard=phpcs.xml
composer audit
```

The suite boots actual CodeIgniter routing, security and native SQLite. The HTTP smoke starts an isolated real PHP/CodeIgniter server, creates disposable accounts/database, verifies native cookie/session rotation, missing CSRF, ownership, verb rejection, anonymous cross-site callback POST → 303 → protected GET and forged return without sending a gateway payment request. SDK tests inject deterministic HTTP transport while preserving actual cryptography/native persistence; no live Lunixi transaction is created. Tests cover SQL-trigger rollback/retry, replay/tamper/environment, exact money, stable lost-response keys, signed recovery, 24h retention fence and FAILED review.

Before deployment complete a registered TEST-merchant hosted/embedded checkout and genuine signed webhook on your HTTPS application. Full commerce fulfillment, your production identity system and remote side effects remain application responsibilities. See the SDK [security policy](../../SECURITY.md).

Primary references: [CodeIgniter requirements](https://codeigniter.com/user_guide/intro/requirements.html), [Composer installation](https://codeigniter.com/user_guide/installation/installing_composer.html), [CSRF](https://codeigniter.com/user_guide/libraries/security.html), [transactions](https://codeigniter.com/user_guide/database/transactions.html), [native feature testing](https://codeigniter.com/user_guide/testing/feature.html).
