# Migrating to 1.0.0

Version 1.0.0 introduces a breaking cutover. Upgrade the API, PHP SDK and affected integration together; do not send numeric money to the new API.

All monetary minor units are strings: `"1000"`, not a JSON number. Request builders, array payloads, response accessors, basket prices, note schedules, split legs, payment links, subscription prices, premiums, fees and balances keep their existing field names. Counts, timestamps, installment counts, rates and quantities keep their existing types.

```php
declare(strict_types=1);

use Lunixi\Sdk\Commerce\Money;
use Lunixi\Sdk\Payment\CreateIntentRequest;

$currency = 'KWD'; // Use the order's persisted currency.
$minor = Money::minor('12.345', Money::exponent($currency)); // '12345'
$request = new CreateIntentRequest($minor, $currency, 'order-42');
$remaining = Money::subtract('9007199254740993', '1');
```

Use decimal text from the commerce platform's persisted DECIMAL fields. Never cast a float into a string, multiply by 100, or use PHP arithmetic/comparison operators for money. `Money::add`, `subtract`, `compare`, `minimum` and `prorate` operate exactly. `Money::decimal` and `minor` require an explicit exponent; JPY uses 0, TRY/USD 2, and KWD 3. Unsupported currencies fail closed.

Positive money matches `[1-9][0-9]*`; nonnegative amounts such as zero-price basket items use `0` or that positive form. Signed balances also permit a leading minus on nonzero values. Whitespace, leading zeroes, plus signs, negative zero, decimal/exponent notation, floats and numeric JSON values are rejected. Fiat payment amounts must not exceed `9223372036854775807`; providers and native platforms can impose smaller limits. The crypto wire domain is not globally limited to signed64.

Use `captureRemaining($intentId, $idempotencyKey)` or `refundRemaining($intentId, $reason, $idempotencyKey)` to request the remaining amount; these methods omit `amount` from the wire body. `capture()` and `refund()` require a positive canonical string amount. Explicit `null`, numeric values and the string `"0"` are invalid. This also avoids ambiguity with PHP positional and named arguments when supplying later reason/idempotency arguments. Commerce mutation helpers retain their requirement for a positive explicit amount when reserving a native operation.

Retain signed webhook bytes unchanged for verification. Do not rewrite event payloads, clear deduplication records, regenerate keys, or reopen paid orders during this upgrade. Old numeric snapshots and unresolved operations are not silently converted: they remain held for reconciliation. Preserve their original request body, fingerprint and idempotency key. Deploy after reconciling old pending operations, or provide a separately reviewed legacy migration that preserves those identities; an unsafe historical numeric value cannot be recovered from its rounded form.

Composer consumers require `lunixi/php-sdk:^1.0`. Scoped merchant bundles must pin the 1.0.0 SDK. Install Composer dependencies after PHP SDK 1.0.0 is available on Packagist. For local acceptance, `SDK_SOURCE` can point at the reviewed 1.0.0 source. WordPress also requires `SDK_SOURCE_REFERENCE` equal to the exact clean SDK commit; its artifact records `reviewed-local` provenance. Public bundles must use a verified immutable 1.0.0 SDK export reference.

Public monetary parameters are deliberately untyped at PHP admission (documented as `mixed`) and checked with `Money` before assignment or transport. PHP scalar `string` hints would coerce integers from callers without `declare(strict_types=1)`; numeric values are rejected in both caller modes. Stored values and successful return values remain strings.

## Physical POS money contract

Physical POS uses the same canonical minor-unit string contract in HTTP requests, responses and verified webhooks. This includes `amountMinor`, `unitPriceMinor`, `lineTotalMinor`, refund-item confirmation amounts, transaction amount filters, tax bases/amounts and settlement totals. PHP payment amount accessors return `?string`; numeric counters (`quantityMilli`, `lineNo`, `installmentCount`, `rowVersion`, tax rates and counts) keep their numeric types.

Use a positive string for a payment or an explicitly supplied refund/pre-authorisation-close amount. `unitPriceMinor` may be `"0"`. Omit the amount for VOID/PREAUTH_CANCEL; an item-based refund may omit its amount so the gateway derives it. An explicit JSON null, number, blank string or noncanonical string is rejected before transport, including nested item amounts. The response's `approvedAmountMinor: null` still means approval is not available. There is no numeric compatibility path.

Values such as `"9007199254740993"` remain exact up to the signed64 limit. This is a transport guarantee, not a promise that every device/provider accepts that amount. A 2xx payment response with malformed monetary fields has an unknown outcome: retain the same idempotency key and reconcile; do not create a new payment key. Query/verified-webhook money is validated without changing the signed raw body.
