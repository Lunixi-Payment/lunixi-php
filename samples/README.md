# Lunixi PHP SDK Samples

Production-oriented examples for merchant backends. They mirror the current
`api-gateway` payment contract and avoid putting card data or secrets in source.

## Setup

```bash
cd entegrasyon/php
composer install
cp samples/.env.example samples/.env
```

Fill `samples/.env`, then run a sample:

```bash
php samples/02-payments/create-checkout-intent.php
```

## Layout

| Directory | Purpose |
|---|---|
| `01-auth` | Key generation and token exchange checks |
| `02-payments` | Hosted checkout (incl. pre-authorization), card through `directMethodPayment`, payment methods list, Faturana Yansıt with OTP, split payment (stored card + Faturana Yansıt), capture, refund, void |
| `03-cards` | Verify-and-store, list cards, charge a stored card |
| `04-reporting` | Payment reads, BIN/installments, analytics |
| `05-webhooks` | Signature verification and endpoint skeleton |
| `06-fraud` | Standalone decision, and feeding the event backbone (single + batch) |
| `07-payment-links` | Create (invoice, tickets), list, update and pause, send, QR, payments, image upload |
| `08-wallet` | End user, balances and limits, W2W quote → initiate → complete, payment request → approve, card top-up, QR code, bulk payout (maker creates and submits, a second key approves and processes), operations |
| `09-bank-accounts` | Connections and accounts with their data freshness, balances, incoming transfers matched to orders, a fresh-read request (with the 429 wait), the `bank_account.transaction.received` webhook endpoint |
| `10-insurance` | Ticket cancellation insurance sold with your payment (eligibility, quote, order, premium item, bind), insurance data on the checkout item (prefill, quote on the payment), a premium collected outside Lunixi with the policy request, cancel and refund, policy PDF, orders, reconciliation summary and CSV, reference codes, the `insurance.*` webhook endpoint |

## Safety Notes

- Prefer hosted checkout (`createIntent`) for SAQ-A. Direct card samples put PAN
  on your server and require PCI DSS SAQ-D controls.
- Every payment sends the order details: the buyer, the billing address and the
  basket items (their `price` values add up to the amount), plus the shipping
  address when an item is `PHYSICAL`. The SDK refuses an incomplete order with
  a `ConfigurationException` before anything is sent (`OrderDetails`); card
  storage sends the buyer and billing address without a basket, and a wallet
  card top-up sends `buyer` and `billingAddress`. `buyer.ip` is the buyer's
  browser IP: the samples use the web request's `REMOTE_ADDR`, or
  `LUNIXI_BUYER_IP` from the command line. The sample values (`sample_buyer()`,
  `sample_billing_address()`, `sample_shipping_address()`) are fictional; take
  the real ones from your order.
- Always pass stable `Idempotency-Key` values for money-moving calls, and for
  payment-link create/send, every wallet route that takes one and the insurance
  order writes (create, confirm payment, issue policy, cancel, refund premium).
- Wallet amounts are minor-unit digit strings (`"12550"` = 125.50 TRY), never
  floats.
- Webhooks are the authority for order finalization; browser redirects are not.
- Treat `AWAITING_3D` and `NEEDS_RECONCILIATION` as non-final states.
