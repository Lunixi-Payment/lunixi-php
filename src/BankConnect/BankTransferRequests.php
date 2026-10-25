<?php

declare(strict_types=1);

namespace Lunixi\Sdk\BankConnect;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Lunixi\Sdk\ApiClient;
use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Exception\ConfigurationException;

/**
 * Request building shared by BankTransferClient and BankFxClient: every call
 * follows BankTransferRoutes, is signed, and its body and query are checked
 * before anything is sent (the gateway rejects an unknown field with 400).
 *
 * @internal
 */
final class BankTransferRequests
{
    private const AMOUNT_FIELDS = ['amountMinor', 'sourceAmountMinor', 'targetAmountMinor'];

    /** `Number.MAX_SAFE_INTEGER`: the gateway's ceiling for an amount. */
    private const MAX_AMOUNT_MINOR = 9007199254740991;

    private const CURSOR_MAX_LENGTH = 2048;

    private function __construct()
    {
    }

    /**
     * @param array<string,string>     $pathParams
     * @param array<string,scalar>     $query
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     * @throws ApiException
     */
    public static function send(
        ApiClient $api,
        string $routeName,
        array $pathParams = [],
        array $query = [],
        ?array $body = null,
        ?string $idempotencyKey = null
    ): array {
        $route = BankTransferRoutes::ROUTES[$routeName];
        $path = (string) preg_replace_callback(
            '/:([A-Za-z]+)/',
            static fn (array $m): string => rawurlencode($pathParams[$m[1]] ?? ''),
            $route['path']
        );
        $options = ['stepUp' => true];
        if ($query !== []) {
            $options['query'] = $query;
        }
        if ($route['idempotencyKey']) {
            $options['idempotencyKey'] = $idempotencyKey;
        }

        return $api->request($route['method'], $path, $body, $options);
    }

    /** A caller's key is checked; a missing one is generated (UUID v4). */
    public static function idempotencyKey(?string $value): string
    {
        if ($value === null || $value === '') {
            $bytes = random_bytes(16);
            $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
            $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
            $hex = bin2hex($bytes);

            return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
        }
        if (preg_match(BankTransferRoutes::IDEMPOTENCY_KEY_PATTERN, $value) !== 1) {
            throw new ConfigurationException('idempotencyKey must be 8-200 printable ASCII characters (no spaces).');
        }

        return $value;
    }

    /**
     * Whitelists the body against the route: unknown or missing fields fail
     * before sending; amounts must be positive integers; required texts are trimmed.
     *
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public static function body(string $routeName, array $request): array
    {
        $route = BankTransferRoutes::ROUTES[$routeName];
        $allowed = array_merge($route['required'], $route['optional']);
        $body = [];
        foreach ($request as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (!in_array((string) $key, $allowed, true)) {
                throw new ConfigurationException("{$routeName} does not accept {$key} (the gateway rejects unknown fields with 400).");
            }
            $body[(string) $key] = in_array((string) $key, self::AMOUNT_FIELDS, true) ? self::amount((string) $key, $value) : $value;
        }
        $missing = array_values(array_diff($route['required'], array_keys($body)));
        if ($missing !== []) {
            throw new ConfigurationException("{$routeName} requires " . implode(', ', $missing) . '.');
        }
        foreach ($route['required'] as $key) {
            if (!in_array($key, self::AMOUNT_FIELDS, true)) {
                $body[$key] = self::text($key, $body[$key]);
            }
        }

        return $body;
    }

    /** @param mixed $value */
    public static function description($value): void
    {
        if (!is_string($value) || self::chars($value) > BankTransferRoutes::DESCRIPTION_MAX) {
            throw new ConfigurationException("'description' must be a string of at most " . BankTransferRoutes::DESCRIPTION_MAX . ' characters.');
        }
    }

    /**
     * List filters of listTransfers / listFxOrders.
     *
     * @param array<string,mixed> $filters
     * @return array<string,scalar>
     */
    public static function query(string $routeName, array $filters): array
    {
        $allowed = BankTransferRoutes::ROUTES[$routeName]['query'];
        $unknown = array_diff(array_map('strval', array_keys($filters)), $allowed);
        if ($unknown !== []) {
            throw new ConfigurationException(sprintf(
                'Unknown filter(s): %s. Allowed: %s. The gateway rejects unknown query fields with 400.',
                implode(', ', $unknown),
                implode(', ', $allowed)
            ));
        }
        $query = [];
        foreach ($allowed as $key) {
            $value = $filters[$key] ?? null;
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $query[$key] = self::filterValue($routeName, $key, $value);
        }

        return $query;
    }

    /** Characters (code points) of a UTF-8 string, without ext-mbstring. */
    public static function chars(string $value): int
    {
        $count = preg_match_all('/./us', $value);

        return $count === false ? strlen($value) : $count;
    }

    public static function requireId(string $value, string $field): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw new ConfigurationException("'{$field}' is required.");
        }

        return $trimmed;
    }

    /**
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    public static function dataOf(array $response): array
    {
        return isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
    }

    /**
     * @param mixed $value
     * @return scalar
     */
    private static function filterValue(string $routeName, string $key, $value)
    {
        switch ($key) {
            case 'limit':
                if (!is_int($value) || $value < 1 || $value > BankConnectClient::MAX_LIMIT) {
                    throw new ConfigurationException("'limit' must be an integer from 1 to " . BankConnectClient::MAX_LIMIT . '.');
                }
                return $value;
            case 'cursor':
                if (!is_string($value) || strlen($value) > self::CURSOR_MAX_LENGTH) {
                    throw new ConfigurationException("'cursor' must be the nextCursor of the previous page, passed back unchanged.");
                }
                return $value;
            case 'status':
                $set = $routeName === 'listFxOrders' ? BankFxOrderStatus::ALL : BankTransferStatus::ALL;
                $values = is_array($value) ? array_values($value) : [$value];
                foreach ($values as $entry) {
                    if (!is_string($entry) || !in_array($entry, $set, true)) {
                        throw new ConfigurationException("'status' must be one of " . implode(', ', $set) . '.');
                    }
                }
                return implode(',', $values);
            case 'accountId':
                return self::requireId(is_string($value) ? $value : '', 'accountId');
            case 'createdFrom':
            case 'createdTo':
                if ($value instanceof DateTimeInterface) {
                    $utc = DateTimeImmutable::createFromFormat('U.u', $value->format('U.u'), new DateTimeZone('UTC'));
                    if ($utc === false) {
                        throw new ConfigurationException("'{$key}' is not a valid time.");
                    }
                    return $utc->format('Y-m-d\TH:i:s.v\Z');
                }
                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
                throw new ConfigurationException("'{$key}' must be a DateTimeInterface or an ISO-8601 string.");
        }

        throw new ConfigurationException("Unknown filter '{$key}'.");
    }

    /** @param mixed $value */
    private static function amount(string $key, $value): int
    {
        if (!is_int($value) || $value < 1 || $value > self::MAX_AMOUNT_MINOR) {
            throw new ConfigurationException("'{$key}' must be a positive integer in minor units (150075 = 1,500.75 TRY).");
        }

        return $value;
    }

    /** @param mixed $value */
    private static function text(string $key, $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new ConfigurationException("'{$key}' must be a non-empty string.");
        }

        return trim($value);
    }
}
