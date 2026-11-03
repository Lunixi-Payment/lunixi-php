<?php

declare(strict_types=1);

namespace Lunixi\Sdk\PhysicalPos;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Generator;
use Lunixi\Sdk\Commerce\Money;
use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Exception\PhysicalPosCardDataRejectedException;
use Lunixi\Sdk\Exception\PhysicalPosRateLimitException;
use Lunixi\Sdk\Exception\PhysicalPosVoidAfterSettlementException;

/**
 * Request checks shared by PhysicalPosClient and PhysicalPosManagementClient.
 *
 * Only the shape is checked before sending (known fields, JSON types, the
 * Idempotency-Key format, canonical money, no card data); the value rules (operation set,
 * provider-specific limits, item totals, reason codes …) belong to the gateway, which
 * answers with a typed `physical_pos.*` code.
 *
 * @internal
 */
final class PhysicalPosRequest
{
    /** `Idempotency-Key`: 8–200 printable ASCII characters, no spaces. */
    public const IDEMPOTENCY_KEY_PATTERN = '/^[\x21-\x7E]{8,200}$/';

    /** Largest page (`limit` 1–100). */
    public const MAX_LIMIT = 100;

    /** Largest `rowVersion` (and int32 fields). */
    public const INT32_MAX = 2147483647;

    /** JavaScript's Number.MAX_SAFE_INTEGER — the gateway's integer ceiling. */
    private const MAX_SAFE = 9007199254740991;

    private const CURSOR_MAX_LENGTH = 2048;

    /**
     * Typed 5xx codes with which the gateway says nothing was written (the
     * request never started a payment): retrying with the same key is safe and
     * the outcome is not uncertain. Every other 5xx is uncertain.
     */
    public const NOT_STARTED_CODES = [
        'physical_pos.presence.unavailable',
        'physical_pos.signing.unavailable',
        'physical_pos.service.busy',
        'physical_pos.service.clock_skew',
        'physical_pos.service.not_configured',
    ];

    /** PAN scan modes (the gateway's `containsPanLikeValue`). */
    public const PAN_STRICT = 'STRICT';
    public const PAN_ITEM_TEXT = 'ITEM_TEXT';

    /**
     * A 13–19 digit run (spaces / dashes allowed) that is a whole token: no
     * letter or digit — nor "letter-or-digit + dash" — right before it, and none
     * right after it. UUIDs and ids glued to letters (`ORD-4111…`) never match.
     */
    private const PAN_CANDIDATE = '/(?<![\p{L}\p{N}])(?<![\p{L}\p{N}]-)[0-9](?:[ -]?[0-9]){12,18}(?![\p{L}\p{N}])(?!-[\p{L}\p{N}])/u';

    /** Filters whose value is free text and is scanned for a card number. */
    private const PAN_SCANNED_FILTERS = ['merchantReference', 'q'];

    /** Whole-key names that are card data only when they are the entire key. */
    private const WHOLE_KEY_TERMS = ['track', 'expiry', 'expiration', 'pin', 'cid', 'csc'];

    /** Card number family — exempt when the key also carries a `masked` / `mask` token. */
    private const CARD_NUMBER_TERMS = [
        'pan', 'pannumber', 'cardpan', 'cardnumber', 'cardno', 'cardnum', 'cardnr', 'ccnumber', 'ccnum',
        'primaryaccountnumber',
    ];

    /** Security code, track data, expiry, PIN — no mask exemption. */
    private const CARD_SECRET_TERMS = [
        'cvv', 'cvv2', 'cvc', 'cvc2', 'cvn', 'cvn2', 'cav2', 'cvvcode', 'cvccode', 'cardcode', 'securitycode',
        'cardverificationcode', 'cardverificationvalue', 'track1', 'track2', 'track3', 'tracki', 'trackii',
        'trackiii', 'trackdata', 'magstripe', 'magneticstripe', 'pinblock', 'pincode', 'pinnumber', 'pindata',
        'cardpin', 'cardholderpin', 'encryptedpin', 'expdate', 'expmonth', 'expyear', 'expmmyy', 'expirydate',
        'expirymonth', 'expiryyear', 'expirymmyy', 'expirationdate', 'expirationmonth', 'expirationyear',
        'expiremonth', 'expireyear', 'cardexpiry', 'cardexpiration',
    ];

    /** Distinctive terms that mark a key ending with them (vendor prefixes such as `customercardnumber`). */
    private const CARD_SUFFIX_TERMS = [
        'cardnumber', 'pannumber', 'primaryaccountnumber', 'cvv', 'cvv2', 'cvc', 'cvc2', 'cvvcode', 'securitycode',
        'track1', 'track2', 'trackdata', 'pinblock', 'expmonth', 'expyear', 'expirydate', 'expirymonth',
        'expiryyear', 'expirationdate', 'cardexpiry',
    ];

    private function __construct()
    {
    }

    // ── Card data ─────────────────────────────────────────────────────────────

    /**
     * Whether a key name is card data (same token rule as the gateway's
     * physical POS card-data gate). Only the NAME is read.
     */
    public static function isCardDataKey(string $key): bool
    {
        if (preg_match('/[0-9](?:[ -]?[0-9]){12,}/', $key) === 1) {
            return true;
        }
        $tokens = self::tokens($key);
        if ($tokens === []) {
            return false;
        }
        if (in_array(implode('', $tokens), self::WHOLE_KEY_TERMS, true)) {
            return true;
        }
        $masked = in_array('masked', $tokens, true) || in_array('mask', $tokens, true);
        $count = count($tokens);
        for ($start = 0; $start < $count; $start++) {
            $joined = '';
            for ($end = $start; $end < $count; $end++) {
                $joined .= $tokens[$end];
                $isNumber = in_array($joined, self::CARD_NUMBER_TERMS, true);
                if (($isNumber && !$masked) || in_array($joined, self::CARD_SECRET_TERMS, true)) {
                    return true;
                }
                foreach (self::CARD_SUFFIX_TERMS as $term) {
                    $suffixIsNumber = in_array($term, self::CARD_NUMBER_TERMS, true);
                    if (self::endsWith($joined, $term) && !($masked && $suffixIsNumber)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Path of the first card-data key in a body or filter array, or null.
     * A key holding a long digit run (a card number used as a key) is reported
     * by its parent path so the number itself never appears.
     *
     * @param mixed $value
     */
    public static function findCardDataField($value, string $root = '(body)'): ?string
    {
        $stack = [[$value, '']];
        while ($stack !== []) {
            [$node, $path] = array_pop($stack);
            if (!is_array($node)) {
                continue;
            }
            if (self::isList($node)) {
                for ($i = count($node) - 1; $i >= 0; $i--) {
                    $stack[] = [$node[$i], $path . '[' . $i . ']'];
                }
                continue;
            }
            foreach (array_keys($node) as $key) {
                $name = (string) $key;
                if (preg_match('/[0-9](?:[ -]?[0-9]){12,}/', $name) === 1) {
                    return $path === '' ? $root : $path;
                }
                if (self::isCardDataKey($name)) {
                    return self::childPath($path, $name);
                }
            }
            foreach (array_reverse(array_keys($node)) as $key) {
                $stack[] = [$node[$key], self::childPath($path, (string) $key)];
            }
        }

        return null;
    }

    /**
     * @param mixed $value
     * @throws PhysicalPosCardDataRejectedException
     */
    public static function assertNoCardData($value, string $root = '(body)'): void
    {
        $field = self::findCardDataField($value, $root);
        if ($field !== null) {
            throw new PhysicalPosCardDataRejectedException(
                'The request must not contain card data; do not send card numbers, expiry dates or security codes. Field: '
                    . $field . '.',
                $field
            );
        }
    }

    /**
     * Whether a text holds a card number: a 13–19 digit token (spaces / dashes
     * allowed) passing Luhn — the gateway's `containsPanLikeValue`. In
     * ITEM_TEXT mode (item names) an unseparated 13 or 14 digit token with a
     * valid GTIN check digit is a barcode, not a card number.
     *
     * @throws ConfigurationException When the text is not valid UTF-8.
     */
    public static function containsPanLikeValue(string $text, string $mode = self::PAN_STRICT): bool
    {
        if (preg_match('//u', $text) !== 1) {
            throw new ConfigurationException('A text field is not valid UTF-8.');
        }
        if (preg_match_all(self::PAN_CANDIDATE, $text, $matches) === false) {
            throw new ConfigurationException('A text field could not be checked for card data.');
        }
        foreach ($matches[0] as $match) {
            $digits = str_replace([' ', '-'], '', $match);
            $length = strlen($digits);
            if ($length < 13 || $length > 19) {
                continue;
            }
            if ($mode === self::PAN_ITEM_TEXT && $digits === $match && $length <= 14 && self::isValidGtin($digits)) {
                continue;
            }
            if (self::luhnValid($digits)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Refuses a free text value holding a card number. Only the field path is
     * reported, never the value.
     *
     * @param mixed $value
     * @throws PhysicalPosCardDataRejectedException
     */
    public static function assertNoPanValue($value, string $field, string $mode = self::PAN_STRICT): void
    {
        if (is_string($value) && $value !== '' && self::containsPanLikeValue($value, $mode)) {
            throw new PhysicalPosCardDataRejectedException(
                'The request must not contain card data: a card number was found in a text field. Field: ' . $field . '.',
                $field
            );
        }
    }

    /**
     * The free text of a payment start or reversal body: `merchantReference`
     * and `items[i].taxGroupCode` (strict), `items[i].name` (barcodes allowed).
     *
     * @param array<string,mixed> $payload The checked payload.
     * @throws PhysicalPosCardDataRejectedException
     */
    public static function assertNoPanInPayment(array $payload): void
    {
        self::assertNoPanValue($payload['merchantReference'] ?? null, 'merchantReference');
        $items = $payload['items'] ?? [];
        foreach (is_array($items) ? $items : [] as $i => $item) {
            $row = (array) $item;
            self::assertNoPanValue($row['name'] ?? null, 'items[' . $i . '].name', self::PAN_ITEM_TEXT);
            self::assertNoPanValue($row['taxGroupCode'] ?? null, 'items[' . $i . '].taxGroupCode');
        }
    }

    // ── Bodies ────────────────────────────────────────────────────────────────

    /**
     * Checks a request body against a field schema and returns the payload to
     * send. Money is canonical string-only; present null/blank money is rejected.
     * Fields left out stay out. For non-money fields, `null` is sent only for a nullable field
     * (it clears the value); on any other optional field it is dropped. An
     * empty string is dropped on an optional field and refused on a required
     * one: an empty value is never sent.
     *
     * Schema types: `money`, `moneyZero`, `string`, `?string`, `int`, `?int`, `rowVersion`, `bool`, `strings`
     * (list of strings) and nested `{object}` / `[list]` schemas given as
     * `['object' => schema]`, `['?object' => schema]`, `['list' => schema]`.
     *
     * @param mixed $request
     * @param array<string,mixed> $schema
     * @param string[] $required
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws PhysicalPosCardDataRejectedException
     */
    public static function body($request, array $schema, array $required, string $name): array
    {
        if (!is_array($request)) {
            throw new ConfigurationException("The {$name} must be an array.");
        }
        self::assertNoCardData($request);

        return self::object($request, $schema, $required, $name, '');
    }

    /**
     * @param array<int|string,mixed> $value
     * @param array<string,mixed> $schema
     * @param string[] $required
     * @return array<string,mixed>
     */
    private static function object(array $value, array $schema, array $required, string $name, string $path): array
    {
        if ($value !== [] && self::isList($value)) {
            throw new ConfigurationException(($path === '' ? "The {$name}" : "'{$path}'") . ' must be an object (string keys).');
        }
        $unknown = array_diff(array_map('strval', array_keys($value)), array_keys($schema));
        if ($unknown !== []) {
            throw new ConfigurationException(sprintf(
                'Unknown %s field(s): %s. Allowed: %s. The gateway rejects unknown fields with 400.',
                $path === '' ? $name : $path,
                implode(', ', $unknown),
                implode(', ', array_keys($schema))
            ));
        }
        foreach ($required as $field) {
            if (!array_key_exists($field, $value) || $value[$field] === null || $value[$field] === '') {
                throw new ConfigurationException("'" . self::childPath($path, $field) . "' is required.");
            }
        }

        $out = [];
        foreach ($schema as $field => $type) {
            if (!array_key_exists($field, $value)) {
                continue;
            }
            $entry = $value[$field];
            if ($type === 'money' || $type === 'moneyZero') {
                $out[$field] = $type === 'money' ? Money::positive($entry, self::childPath($path, $field)) : Money::nonnegative($entry, self::childPath($path, $field));
                continue;
            }
            if ($entry === '') {
                continue;
            }
            if ($entry === null && !self::nullable($type)) {
                continue;
            }
            $out[$field] = self::typed($entry, $type, $name, self::childPath($path, $field));
        }

        return $out;
    }

    /** @param string|array<string,mixed> $type */
    private static function nullable($type): bool
    {
        $kind = is_array($type) ? (string) array_key_first($type) : $type;

        return $kind !== '' && $kind[0] === '?';
    }

    /**
     * @param mixed $value
     * @param string|array<string,mixed> $type
     * @return mixed
     */
    private static function typed($value, $type, string $name, string $path)
    {
        if (is_array($type)) {
            $kind = (string) array_key_first($type);
            $inner = $type[$kind];
            if ($value === null && $kind[0] === '?') {
                return null;
            }
            if (ltrim($kind, '?') === 'object') {
                if (!is_array($value)) {
                    throw new ConfigurationException("'{$path}' must be an object.");
                }
                return (object) self::object($value, $inner['fields'], $inner['required'] ?? [], $name, $path);
            }
            // list: a non-empty list of objects; the gateway rejects an empty array.
            if (!is_array($value) || $value === [] || !self::isList($value)) {
                throw new ConfigurationException("'{$path}' must be a non-empty list; leave the field out instead of sending an empty list.");
            }
            $rows = [];
            foreach ($value as $i => $row) {
                $rowPath = $path . '[' . $i . ']';
                if (!is_array($row)) {
                    throw new ConfigurationException("'{$rowPath}' must be an object.");
                }
                $rows[] = (object) self::object($row, $inner['fields'], $inner['required'] ?? [], $name, $rowPath);
            }
            return $rows;
        }

        $nullable = $type[0] === '?';
        if ($value === null) {
            if ($nullable) {
                return null;
            }
            throw new ConfigurationException("'{$path}' must not be null; leave the field out instead.");
        }
        switch (ltrim($type, '?')) {
            case 'string':
                if (!is_string($value)) {
                    throw new ConfigurationException("'{$path}' must be a string.");
                }
                return $value;
            case 'int':
                return self::integer($value, $path);
            case 'rowVersion':
                $version = self::integer($value, $path);
                if ($version < 1 || $version > self::INT32_MAX) {
                    throw new ConfigurationException("'{$path}' must be between 1 and " . self::INT32_MAX . ': send the rowVersion you last read.');
                }
                return $version;
            case 'bool':
                if (!is_bool($value)) {
                    throw new ConfigurationException("'{$path}' must be a boolean.");
                }
                return $value;
            case 'strings':
                if (!is_array($value) || !self::isList($value)) {
                    throw new ConfigurationException("'{$path}' must be a list of non-empty strings.");
                }
                foreach ($value as $item) {
                    if (!is_string($item) || $item === '') {
                        throw new ConfigurationException("'{$path}' must be a list of strings.");
                    }
                }
                return array_values($value);
        }

        throw new ConfigurationException("Unsupported field type for '{$path}'.");
    }

    /**
     * A JSON integer in the gateway's safe range. A numeric string ("4500")
     * is rejected: the gateway answers it with 400.
     *
     * @param mixed $value
     */
    public static function integer($value, string $field): int
    {
        if (!is_int($value) || $value > self::MAX_SAFE || $value < -self::MAX_SAFE) {
            throw new ConfigurationException("'{$field}' must be an integer (a JSON number, not a string).");
        }

        return $value;
    }

    // ── Filters ───────────────────────────────────────────────────────────────

    /**
     * Query string of a list or report call: only the listed filters; `limit`
     * 1–100, `includeTotal` boolean, `amount*Minor` canonical integer strings, `createdFrom` /
     * `createdTo` ISO-8601 strings or DateTimeInterface (sent in UTC with `Z`),
     * `dateFrom` / `dateTo` `YYYY-MM-DD` strings or DateTimeInterface (sent as
     * the object's OWN calendar day — `format('Y-m-d')` in its time zone, not
     * converted to Europe/Istanbul). Null and empty non-money values are dropped. A card
     * number in `merchantReference` or `q` is refused before sending.
     *
     * @param mixed $filters
     * @param string[] $allowed
     * @return array<string,string|int>
     * @throws ConfigurationException
     * @throws PhysicalPosCardDataRejectedException
     */
    public static function query($filters, array $allowed, string $route): array
    {
        if (!is_array($filters)) {
            throw new ConfigurationException("{$route} filters must be an array.");
        }
        self::assertNoCardData($filters, '(query)');
        $unknown = array_diff(array_map('strval', array_keys($filters)), $allowed);
        if ($unknown !== []) {
            throw new ConfigurationException(sprintf(
                'Unknown %s filter(s): %s. Allowed: %s. The gateway rejects unknown query fields with 400.',
                $route,
                implode(', ', $unknown),
                $allowed === [] ? '(none)' : implode(', ', $allowed)
            ));
        }

        $query = [];
        foreach ($allowed as $key) {
            $value = $filters[$key] ?? null;
            if (array_key_exists($key, $filters) && in_array($key, ['amountFromMinor', 'amountToMinor'], true)) {
                $query[$key] = Money::positive($value, $key);
                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }
            $query[$key] = self::filterValue($key, $value);
            if (in_array($key, self::PAN_SCANNED_FILTERS, true)) {
                self::assertNoPanValue($query[$key], $key);
            }
        }

        return $query;
    }

    /**
     * @param mixed $value
     * @return string|int
     */
    private static function filterValue(string $key, $value)
    {
        switch ($key) {
            case 'limit':
                if (!is_int($value) || $value < 1 || $value > self::MAX_LIMIT) {
                    throw new ConfigurationException("'limit' must be an integer from 1 to " . self::MAX_LIMIT . '.');
                }
                return $value;
            case 'cursor':
                if (!is_string($value) || strlen($value) > self::CURSOR_MAX_LENGTH) {
                    throw new ConfigurationException("'cursor' must be the nextCursor of the previous page, passed back unchanged.");
                }
                return $value;
            case 'includeTotal':
                if (!is_bool($value)) {
                    throw new ConfigurationException("'includeTotal' must be a boolean.");
                }
                return $value ? 'true' : 'false';
            case 'amountFromMinor':
            case 'amountToMinor':
                return Money::positive($value, $key);
            case 'createdFrom':
            case 'createdTo':
                if ($value instanceof DateTimeInterface) {
                    return self::utc($value, $key);
                }
                break;
            case 'dateFrom':
            case 'dateTo':
                if ($value instanceof DateTimeInterface) {
                    return $value->format('Y-m-d');
                }
                break;
        }
        if (!is_string($value)) {
            throw new ConfigurationException("'{$key}' must be a string.");
        }

        return $value;
    }

    private static function utc(DateTimeInterface $value, string $field): string
    {
        $utc = DateTimeImmutable::createFromFormat('U.u', $value->format('U.u'), new DateTimeZone('UTC'));
        if ($utc === false) {
            throw new ConfigurationException("'{$field}' is not a valid time.");
        }

        return $utc->format('Y-m-d\TH:i:s.v\Z');
    }

    // ── Ids, keys ─────────────────────────────────────────────────────────────

    /**
     * A path id, URL-encoded as given. Empty / blank ids and the dot segments
     * `.` and `..` (which a URL parser would collapse into another route) are
     * refused.
     */
    public static function pathId(string $value, string $field): string
    {
        if (trim($value) === '') {
            throw new ConfigurationException("'{$field}' is required.");
        }
        if ($value === '.' || $value === '..') {
            throw new ConfigurationException("'{$field}' is not a valid id.");
        }

        return rawurlencode($value);
    }

    /**
     * The caller's key, checked as given (never trimmed), or — ONLY for null —
     * a new random UUID v4. An empty or blank key is refused: it would
     * otherwise silently become a different key on every call. The key is
     * fixed for the whole call, so every attempt sends the same key.
     *
     * @throws ConfigurationException
     */
    public static function idempotencyKey(?string $key): string
    {
        if ($key === null) {
            return self::uuid4();
        }

        return self::checkIdempotencyKey($key);
    }

    /**
     * A key the caller MUST pass (payment start and reversal): null is refused.
     *
     * @throws ConfigurationException
     */
    public static function requireIdempotencyKey(?string $key, string $operation): string
    {
        if ($key === null) {
            throw new ConfigurationException(sprintf(
                'A stable Idempotency-Key is required for %s. Create it with PhysicalPosClient::newIdempotencyKey(), '
                    . 'store it with your order, then call; when the outcome is unknown call again with the SAME key.',
                $operation
            ));
        }

        return self::checkIdempotencyKey($key);
    }

    /** Whether a key has the `Idempotency-Key` format (8–200 printable ASCII, no spaces), as given. */
    public static function isValidIdempotencyKey(string $key): bool
    {
        return preg_match(self::IDEMPOTENCY_KEY_PATTERN, $key) === 1;
    }

    private static function checkIdempotencyKey(string $key): string
    {
        if (!self::isValidIdempotencyKey($key)) {
            throw new ConfigurationException(
                'Idempotency-Key must be 8-200 printable ASCII characters without spaces (it is sent as given, never trimmed).'
            );
        }

        return $key;
    }

    public static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    // ── Responses, errors ─────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    public static function data(array $response): array
    {
        return isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
    }

    /**
     * The envelope's `statusCode` (the HTTP status of the answer), or the fallback.
     *
     * @param array<string,mixed> $response
     */
    public static function statusCode(array $response, int $fallback): int
    {
        $code = $response['statusCode'] ?? null;

        return is_int($code) && $code >= 200 && $code < 300 ? $code : $fallback;
    }

    /**
     * Typed error of an answered request: 429 → rate limit, card data code →
     * card data, void after end-of-day → void after settlement, anything else
     * unchanged.
     */
    public static function mapError(ApiException $error): ApiException
    {
        if (
            $error instanceof PhysicalPosRateLimitException
            || $error instanceof PhysicalPosCardDataRejectedException
            || $error instanceof PhysicalPosVoidAfterSettlementException
        ) {
            return $error;
        }
        $body = $error->getResponseBody() ?? [];
        $details = isset($body['details']) && is_array($body['details']) ? $body['details'] : [];
        if ($error->getStatusCode() === 429) {
            $seconds = $details['retryAfterSeconds'] ?? null;
            return new PhysicalPosRateLimitException(
                $error->getMessage(),
                $error->getErrorCode(),
                $error->getResponseBody(),
                $error->getRequestId(),
                $error->getRetryAfter() ?? (is_int($seconds) && $seconds >= 0 ? $seconds : null),
                $error,
                $error->getResponseHeaders()
            );
        }
        if ($error->getErrorCode() === PhysicalPosCardDataRejectedException::CODE) {
            $field = isset($details['field']) && is_string($details['field']) ? $details['field'] : null;
            return new PhysicalPosCardDataRejectedException(
                $error->getMessage(),
                $field,
                $error->getStatusCode(),
                $error->getResponseBody(),
                $error->getRequestId(),
                $error,
                $error->getResponseHeaders()
            );
        }

        if ($error->getErrorCode() === PhysicalPosVoidAfterSettlementException::CODE) {
            $allowed = isset($details['allowedOperation']) && is_string($details['allowedOperation'])
                ? $details['allowedOperation'] : null;
            return new PhysicalPosVoidAfterSettlementException(
                $error->getMessage(),
                $allowed,
                $error->getStatusCode(),
                $error->getResponseBody(),
                $error->getRequestId(),
                $error,
                $error->getResponseHeaders()
            );
        }

        return $error;
    }

    /**
     * The original payment of a reversal webhook payload: `['paymentId' => …,
     * 'origin' => …]` when `data.original` carries both as non-empty strings
     * (other keys are dropped); null otherwise (a root payment).
     *
     * @param array<string,mixed> $data
     * @return array{paymentId:string,origin:string}|null
     */
    public static function webhookOriginal(array $data): ?array
    {
        $original = $data['original'] ?? null;
        if (!is_array($original)) {
            return null;
        }
        $paymentId = $original['paymentId'] ?? null;
        $origin = $original['origin'] ?? null;
        if (!is_string($paymentId) || $paymentId === '' || !is_string($origin) || $origin === '') {
            return null;
        }

        return ['paymentId' => $paymentId, 'origin' => $origin];
    }

    /** The category of an event type: `payment`, `fiscal_document`, `settlement` or `other`. */
    public static function webhookCategory(string $type): string
    {
        if (strpos($type, 'physical_pos.payment.') === 0) {
            return PhysicalPosWebhookEvent::CATEGORY_PAYMENT;
        }
        if (strpos($type, 'physical_pos.fiscal_document.') === 0) {
            return PhysicalPosWebhookEvent::CATEGORY_FISCAL_DOCUMENT;
        }
        if (strpos($type, 'physical_pos.settlement.') === 0) {
            return PhysicalPosWebhookEvent::CATEGORY_SETTLEMENT;
        }

        return PhysicalPosWebhookEvent::CATEGORY_OTHER;
    }

    /**
     * The cashier of a payment event: `cashierId` and `cashierCode` when both
     * are non-empty strings (other keys, e.g. a name, are dropped); null when
     * there is no cashier.
     *
     * @param array<string,mixed> $data
     * @return array{cashierId:string,cashierCode:string}|null
     */
    public static function webhookCashier(array $data): ?array
    {
        $cashier = $data['cashier'] ?? null;
        if (!is_array($cashier)) {
            return null;
        }
        $cashierId = $cashier['cashierId'] ?? null;
        $cashierCode = $cashier['cashierCode'] ?? null;
        if (!is_string($cashierId) || $cashierId === '' || !is_string($cashierCode) || $cashierCode === '') {
            return null;
        }

        return ['cashierId' => $cashierId, 'cashierCode' => $cashierCode];
    }

    /**
     * Whether a failed payment-write attempt leaves the outcome uncertain: no
     * HTTP answer (status 0, or a Throwable from the transport) or a 5xx that
     * is not one of {@see NOT_STARTED_CODES}. A 4xx is definite.
     */
    public static function isUncertain(\Throwable $error): bool
    {
        if (!$error instanceof ApiException) {
            return true;
        }
        $status = $error->getStatusCode();
        if ($status === 0) {
            return true;
        }

        return $status >= 500 && !in_array($error->getErrorCode(), self::NOT_STARTED_CODES, true);
    }

    /** Whether a failed attempt may be repeated with the same key (uncertain, or a "not started" 5xx). */
    public static function isRetryable(\Throwable $error): bool
    {
        return self::isUncertain($error) || ($error instanceof ApiException && $error->getStatusCode() >= 500);
    }

    /**
     * @template T
     * @param callable(array<string,mixed>):PhysicalPosPage<T> $fetchPage
     * @param array<string,mixed> $filters
     * @return Generator<int,T>
     */
    public static function paginate(callable $fetchPage, array $filters): Generator
    {
        do {
            $page = $fetchPage($filters);
            foreach ($page->items() as $item) {
                yield $item;
            }
            $filters['cursor'] = $page->nextCursor();
        } while ($page->hasMore());
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private static function luhnValid(string $digits): bool
    {
        $sum = 0;
        $length = strlen($digits);
        for ($i = 0; $i < $length; $i++) {
            $digit = (int) $digits[$length - 1 - $i];
            if ($i % 2 === 1) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
        }

        return $sum % 10 === 0;
    }

    /** GS1 check digit of a GTIN-8 / 12 / 13 / 14. */
    private static function isValidGtin(string $digits): bool
    {
        if (preg_match('/^(?:[0-9]{8}|[0-9]{12,14})$/', $digits) !== 1) {
            return false;
        }
        $sum = 0;
        $weight = 3;
        for ($i = strlen($digits) - 2; $i >= 0; $i--) {
            $sum += (int) $digits[$i] * $weight;
            $weight = $weight === 3 ? 1 : 3;
        }

        return (10 - ($sum % 10)) % 10 === (int) $digits[strlen($digits) - 1];
    }

    /** @return string[] */
    private static function tokens(string $key): array
    {
        $spaced = (string) preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $key);
        $spaced = (string) preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1 $2', $spaced);
        $spaced = (string) preg_replace('/([A-Za-z])([0-9])/', '$1 $2', $spaced);
        $spaced = (string) preg_replace('/([0-9])([A-Za-z])/', '$1 $2', $spaced);
        $parts = preg_split('/[^a-z0-9]+/', strtolower($spaced));

        return array_values(array_filter(is_array($parts) ? $parts : [], static fn (string $t): bool => $t !== ''));
    }

    private static function endsWith(string $haystack, string $needle): bool
    {
        return strlen($haystack) >= strlen($needle) && substr($haystack, -strlen($needle)) === $needle;
    }

    private static function childPath(string $path, string $key): string
    {
        if (preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', $key) !== 1) {
            return $path . '[?]';
        }

        return $path === '' ? $key : $path . '.' . $key;
    }

    /** @param array<mixed> $value */
    private static function isList(array $value): bool
    {
        $expected = 0;
        foreach ($value as $key => $_) {
            if ($key !== $expected++) {
                return false;
            }
        }

        return true;
    }
}
