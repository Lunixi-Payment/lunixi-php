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
| `02-payments` | Hosted checkout, direct 2D/3D, capture, refund, void |
| `03-cards` | Verify-and-store, list cards, charge a stored card |
| `04-reporting` | Payment reads, BIN/installments, analytics |
| `05-webhooks` | Signature verification and endpoint skeleton |

## Safety Notes

- Prefer hosted checkout (`createIntent`) for SAQ-A. Direct card samples put PAN
  on your server and require PCI DSS SAQ-D controls.
- Direct 2D/3D samples intentionally require full payment context: card, buyer,
  billing address and basket items. Lunixi does not model a card-only charge as
  a complete auditable payment.
- Always pass stable `Idempotency-Key` values for money-moving calls.
- Webhooks are the authority for order finalization; browser redirects are not.
- Treat `AWAITING_3D` and `NEEDS_RECONCILIATION` as non-final states.
