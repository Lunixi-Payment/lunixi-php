<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

use Lunixi\Sdk\Exception\ConfigurationException;

/**
 * Public payment method codes (`paymentMethod`) and the request rules of
 * POST /api/v1/payments/direct/payment and the action endpoint.
 *
 * The gateway validates bodies with `forbidNonWhitelisted`: a key outside its
 * DTO is a 400. The field lists below are the DTO's (same as the Node SDK's
 * `DIRECT_METHOD_PAYMENT_FIELDS` / `PAYMENT_ACTION_CONFIRM_FIELDS`).
 */
final class PaymentMethod
{
    public const CARD = 'CARD';
    public const BNPL = 'BNPL';
    public const SHOPPING_LOAN = 'SHOPPING_LOAN';
    public const PROMISSORY_NOTE = 'PROMISSORY_NOTE';
    public const MEAL_CARD = 'MEAL_CARD';
    public const GIFT_CARD = 'GIFT_CARD';
    public const CASH = 'CASH';
    public const CASH_ON_DELIVERY = 'CASH_ON_DELIVERY';
    public const WALLET = 'WALLET';
    public const BANK_TRANSFER = 'BANK_TRANSFER';
    public const OPEN_BANKING = 'OPEN_BANKING';
    /** Faturana Yansıt (charge to the mobile phone bill). */
    public const MOBILE_BILLING = 'MOBILE_BILLING';
    public const QR_CODE = 'QR_CODE';
    public const CRYPTO = 'CRYPTO';
    public const OTHER = 'OTHER';

    /** Accepted aliases, mapped to the canonical code. */
    private const ALIASES = ['QR' => self::QR_CODE];

    /**
     * Method blocks the gateway accepts today besides `card`. The block of the
     * chosen method is required; a block for another method is a 400. A method
     * without a block here cannot be paid yet; PaymentClient::listPaymentMethods()
     * lists only the methods that can.
     */
    public const METHOD_BLOCK_FIELDS = ['mobileBilling', 'wallet', 'promissoryNote'];

    /** Fields that belong to a card payment only (`paymentMethod: CARD`). */
    public const CARD_ONLY_FIELDS = ['card', 'installment', 'installmentQuoteToken', 'force3D'];

    /** Body fields of POST /payments/direct/payment (gateway DirectMethodPaymentDto). */
    public const DIRECT_METHOD_PAYMENT_FIELDS = [
        'paymentMethod', 'amount', 'currency', 'orderId', 'paymentChannel',
        'paymentGroup', 'callbackUrl', 'locale', 'description', 'customerId', 'preauth',
        'buyer', 'shippingAddress', 'billingAddress', 'basketItems', 'card', 'installment',
        'installmentQuoteToken', 'force3D', 'metadata', 'mobileBilling', 'wallet',
        'promissoryNote',
    ];

    /**
     * Body fields a merchant server sends to the confirm endpoint. The checkout
     * token fields (`token`, `checkoutSessionProof`) are the hosted form's; a
     * signed request that also carries a token is refused with 400
     * `payment.action.auth_ambiguous`, so the SDK never sends them.
     */
    public const PAYMENT_ACTION_CONFIRM_FIELDS = ['otp', 'consentAccepted', 'fields'];

    /** Limits of a method block / confirm `fields` (same as the gateway). */
    public const FIELDS_MAX_KEYS = 32;
    public const FIELD_MAX_VALUE_LENGTH = 1024;
    /** Items of a GROUP value and fields of an object (ADDRESS) value. */
    public const FIELD_MAX_GROUP_ITEMS = 10;
    public const FIELD_MAX_NESTED_KEYS = 16;

    private const CODE_PATTERN = '/^[A-Z][A-Z0-9_]{0,31}$/';
    private const PROVIDER_CODE_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';
    private const FIELD_KEY_PATTERN = '/^[A-Za-z][A-Za-z0-9]{0,63}$/';
    private const RESERVED_FIELD_KEYS = ['__proto__', 'constructor', 'prototype'];

    private function __construct()
    {
    }

    /** Canonical method code; `QR` becomes `QR_CODE`. */
    public static function normalize(string $paymentMethod): string
    {
        if (preg_match(self::CODE_PATTERN, $paymentMethod) !== 1) {
            throw new ConfigurationException(sprintf(
                'paymentMethod must be a payment method code such as %s; got "%s".',
                self::MOBILE_BILLING,
                $paymentMethod
            ));
        }

        return self::ALIASES[$paymentMethod] ?? $paymentMethod;
    }

    /**
     * Name of the method's block in the request body: the camelCase form of the
     * method code (`MOBILE_BILLING` → `mobileBilling`). It is also returned as
     * `blockName` by PaymentClient::listPaymentMethods().
     */
    public static function blockName(string $paymentMethod): string
    {
        $parts = explode('_', strtolower(self::normalize($paymentMethod)));
        $first = array_shift($parts);

        return $first . implode('', array_map('ucfirst', $parts));
    }

    /** @internal */
    public static function requireProviderCode(string $providerCode): string
    {
        if (preg_match(self::PROVIDER_CODE_PATTERN, $providerCode) !== 1) {
            throw new ConfigurationException(sprintf(
                'providerCode must be a provider code from listPaymentMethods() (letters, digits, _ and -); got "%s".',
                $providerCode
            ));
        }

        return $providerCode;
    }

    /**
     * Structure of a method block or of confirm `fields`. The fields themselves
     * are the provider's declaration (`inputFields`) and are validated by the
     * gateway; the SDK stops a body the gateway would reject for its shape alone:
     *   - at most 32 fields; names start with a letter and hold only letters and digits;
     *   - a value is a string (at most 1024 characters), an int, a finite float
     *     or a bool; an associative array of such values (ADDRESS, at most 16
     *     fields); or a list of at most 10 associative arrays (GROUP) whose
     *     values are scalars or associative arrays of scalars.
     *
     * @internal
     * @param array<mixed> $fields
     * @return array<string,mixed>
     */
    public static function requireFields(array $fields, string $path): array
    {
        if (count($fields) > self::FIELDS_MAX_KEYS) {
            throw new ConfigurationException(sprintf('%s can carry at most %d fields.', $path, self::FIELDS_MAX_KEYS));
        }
        foreach ($fields as $key => $value) {
            if ($key === 'providerCode') {
                throw new ConfigurationException(sprintf('%s must not carry providerCode; pass it as its own argument.', $path));
            }
            self::requireFieldKey($key, $path);
            self::requireFieldValue($value, $path . '.' . $key, true);
        }

        return $fields;
    }

    /** @param int|string $key */
    private static function requireFieldKey($key, string $path): void
    {
        if (!is_string($key) || preg_match(self::FIELD_KEY_PATTERN, $key) !== 1 || in_array($key, self::RESERVED_FIELD_KEYS, true)) {
            throw new ConfigurationException(sprintf(
                '%s has an invalid field name "%s": field names start with a letter and contain only letters and digits.',
                $path,
                (string) $key
            ));
        }
    }

    /** @param mixed $value */
    private static function isScalarFieldValue($value): bool
    {
        return (is_string($value) && self::characterCount($value) <= self::FIELD_MAX_VALUE_LENGTH)
            || is_int($value)
            || (is_float($value) && is_finite($value))
            || is_bool($value);
    }

    /** @param mixed $value */
    private static function requireFieldValue($value, string $path, bool $allowList): void
    {
        if (self::isScalarFieldValue($value)) {
            return;
        }
        if (is_array($value) && $value !== [] && !self::isList($value)) {
            if (count($value) > self::FIELD_MAX_NESTED_KEYS) {
                throw new ConfigurationException(sprintf('%s can carry at most %d fields.', $path, self::FIELD_MAX_NESTED_KEYS));
            }
            foreach ($value as $key => $field) {
                self::requireFieldKey($key, $path);
                if (!self::isScalarFieldValue($field)) {
                    throw new ConfigurationException(sprintf(
                        '%s.%s must be a string (at most %d characters), a number or a boolean.',
                        $path,
                        (string) $key,
                        self::FIELD_MAX_VALUE_LENGTH
                    ));
                }
            }
            return;
        }
        if ($allowList && is_array($value)) {
            if (count($value) > self::FIELD_MAX_GROUP_ITEMS) {
                throw new ConfigurationException(sprintf('%s can carry at most %d items.', $path, self::FIELD_MAX_GROUP_ITEMS));
            }
            foreach ($value as $index => $item) {
                $itemPath = sprintf('%s[%d]', $path, $index);
                if (!is_array($item) || $item === [] || self::isList($item)) {
                    throw new ConfigurationException(sprintf('%s must be an associative array of fields.', $itemPath));
                }
                if (count($item) > self::FIELDS_MAX_KEYS) {
                    throw new ConfigurationException(sprintf('%s can carry at most %d fields.', $itemPath, self::FIELDS_MAX_KEYS));
                }
                foreach ($item as $key => $field) {
                    self::requireFieldKey($key, $itemPath);
                    self::requireFieldValue($field, $itemPath . '.' . $key, false);
                }
            }
            return;
        }

        throw new ConfigurationException(sprintf(
            '%s must be a string (at most %d characters), a number, a boolean, an associative array or a list of associative arrays.',
            $path,
            self::FIELD_MAX_VALUE_LENGTH
        ));
    }

    /** @param array<mixed> $value */
    private static function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }

    /** Characters of a UTF-8 string; invalid UTF-8 counts as too long (the gateway reads JSON as UTF-8). */
    private static function characterCount(string $value): int
    {
        $count = preg_match_all('/./su', $value);

        return $count === false ? PHP_INT_MAX : $count;
    }
}
