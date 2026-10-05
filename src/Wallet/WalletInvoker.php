<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Wallet;

use Lunixi\Sdk\ApiClient;
use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Http\HttpResponse;

/**
 * Sends one route of {@see WalletRoutes::ROUTES}, signed. Shared by the wallet
 * sub-clients; integrations call the sub-clients, not this class.
 *
 * The path, body, query and Idempotency-Key are checked against the route
 * before anything leaves the process. Each of these is a
 * ConfigurationException, not a 400 from the gateway: a blank path id, an
 * unknown body field, a missing required field, an amount that is not a
 * minor-unit digit string, a missing or malformed Idempotency-Key, an
 * Idempotency-Key on a route that does not take one, an unknown query filter.
 *
 * Every wallet route sits behind SignatureGuard, so every call — reads
 * included — carries the API key's per-request Ed25519 signature.
 *
 * @internal
 */
final class WalletInvoker
{
    private ApiClient $api;

    public function __construct(ApiClient $api)
    {
        $this->api = $api;
    }

    /**
     * Sends a JSON route and returns the decoded envelope.
     *
     * @param array<string,string>     $pathParams     Values of the `:name` segments of the path.
     * @param array<string,mixed>|null $body           Body fields; null values are dropped.
     * @param array<string,mixed>|null $query          Query filters; null and empty values are dropped.
     * @return array<string,mixed>
     * @throws ConfigurationException before sending, when the call does not fit the route.
     * @throws ApiException
     */
    public function call(
        string $routeName,
        array $pathParams = [],
        ?array $body = null,
        ?string $idempotencyKey = null,
        ?array $query = null
    ): array {
        $route = self::route($routeName, false);
        [$path, $payload, $options] = self::prepare($routeName, $route, $pathParams, $body, $idempotencyKey, $query);

        return $this->api->request($route['method'], $path, $payload, $options);
    }

    /**
     * Sends a route whose 2xx answer is bytes (an image) and returns the
     * response untouched. An error answer is still read as a JSON error.
     *
     * @param array<string,string>     $pathParams
     * @param array<string,mixed>|null $query
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function callBinary(string $routeName, array $pathParams = [], ?array $query = null): HttpResponse
    {
        $route = self::route($routeName, true);
        [$path, , $options] = self::prepare($routeName, $route, $pathParams, null, null, $query);

        return $this->api->requestBinary($route['method'], $path, $options);
    }

    /**
     * The complete-step DTOs require `operationId` in the body as well as in
     * the path. Fills it from the path argument; a different value in the
     * request is refused rather than sent.
     *
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     * @throws ConfigurationException
     */
    public static function withOperationId(string $operationId, array $request): array
    {
        $operationId = trim($operationId);
        if (isset($request['operationId']) && $request['operationId'] !== $operationId) {
            throw new ConfigurationException('operationId in the body must match the operation being completed.');
        }
        $request['operationId'] = $operationId;

        return $request;
    }

    /**
     * @return array{method:string,path:string,idempotencyKey:bool,required?:string[],optional?:string[],query?:string[],binary?:bool}
     */
    private static function route(string $routeName, bool $binary): array
    {
        $route = WalletRoutes::ROUTES[$routeName] ?? null;
        if ($route === null) {
            throw new ConfigurationException("Unknown wallet route '{$routeName}'.");
        }
        if (($route['binary'] ?? false) !== $binary) {
            throw new ConfigurationException(
                $binary ? "{$routeName} answers JSON, not bytes." : "{$routeName} answers bytes; read it as binary."
            );
        }

        return $route;
    }

    /**
     * @param array{method:string,path:string,idempotencyKey:bool,required?:string[],optional?:string[],query?:string[]} $route
     * @param array<string,string>     $pathParams
     * @param array<string,mixed>|null $body
     * @param array<string,mixed>|null $query
     * @return array{0:string, 1:array<string,mixed>|null, 2:array{stepUp:bool, idempotencyKey?:string, query?:array<string,scalar>}}
     */
    private static function prepare(
        string $routeName,
        array $route,
        array $pathParams,
        ?array $body,
        ?string $idempotencyKey,
        ?array $query
    ): array {
        $path = self::fillPath($route['path'], $pathParams);
        $payload = $body === null ? null : self::checkBody($routeName, $route, $body);

        $options = ['stepUp' => true];
        if ($query !== null) {
            $picked = self::pickQuery($routeName, $route['query'] ?? [], $query);
            if ($picked !== []) {
                $options['query'] = $picked;
            }
        }
        if ($route['idempotencyKey']) {
            $options['idempotencyKey'] = self::requireIdempotencyKey($routeName, $idempotencyKey);
        } elseif ($idempotencyKey !== null) {
            throw new ConfigurationException("{$routeName} does not take an Idempotency-Key.");
        }

        return [$path, $payload, $options];
    }

    /**
     * @param array<string,string> $params
     */
    private static function fillPath(string $template, array $params): string
    {
        return (string) preg_replace_callback('/:([A-Za-z]+)/', static function (array $match) use ($params): string {
            $value = $params[$match[1]] ?? null;
            if (!is_string($value) || trim($value) === '') {
                throw new ConfigurationException("'{$match[1]}' is required.");
            }

            return rawurlencode(trim($value));
        }, $template);
    }

    /**
     * An empty body after dropping nulls is sent as no body at all; the
     * gateway reads it as `{}`.
     *
     * @param array{required?:string[],optional?:string[]} $route
     * @param array<string,mixed> $body
     * @return array<string,mixed>|null
     */
    private static function checkBody(string $routeName, array $route, array $body): ?array
    {
        $required = $route['required'] ?? [];
        $plain = self::fields($routeName, $body, $required, $route['optional'] ?? []);

        foreach (WalletRoutes::AMOUNT_FIELDS as $field) {
            if (array_key_exists($field, $plain)) {
                $plain[$field] = self::requireAmount("{$routeName}.{$field}", $plain[$field]);
            }
        }
        if (isset(WalletRoutes::NESTED_FIELDS[$routeName])) {
            $listField = WalletRoutes::NESTED_LIST_FIELDS[$routeName];
            $plain[$listField] = self::checkRows(
                "{$routeName}.{$listField}",
                WalletRoutes::NESTED_FIELDS[$routeName],
                $plain[$listField] ?? null
            );
        }
        foreach (WalletRoutes::OBJECT_FIELDS as $field) {
            if (isset($plain[$field]) && is_array($plain[$field])) {
                $plain[$field] = (object) $plain[$field];
            }
        }

        return $plain === [] ? null : $plain;
    }

    /**
     * @param array{required:string[],optional:string[]} $spec
     * @param mixed $rows
     * @return array<int,array<string,mixed>>
     */
    private static function checkRows(string $label, array $spec, $rows): array
    {
        if (!is_array($rows) || $rows === [] || !self::isList($rows)) {
            throw new ConfigurationException("{$label} must be a non-empty list.");
        }
        $checked = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                throw new ConfigurationException("{$label}[{$index}] must be an object.");
            }
            $plain = self::fields("{$label}[{$index}]", $row, $spec['required'], $spec['optional']);
            if (array_key_exists('amount', $plain)) {
                $plain['amount'] = self::requireAmount("{$label}[{$index}].amount", $plain['amount']);
            }
            $checked[] = $plain;
        }

        return $checked;
    }

    /**
     * Drops null values, then refuses unknown and missing fields.
     *
     * @param array<int|string,mixed> $object
     * @param string[] $required
     * @param string[] $optional
     * @return array<string,mixed>
     */
    private static function fields(string $label, array $object, array $required, array $optional): array
    {
        $plain = array_filter($object, static fn ($value): bool => $value !== null);
        $allowed = array_merge($required, $optional);

        $unknown = [];
        foreach (array_keys($plain) as $key) {
            if (!in_array((string) $key, $allowed, true)) {
                $unknown[] = (string) $key;
            }
        }
        if ($unknown !== []) {
            throw new ConfigurationException(
                "{$label} does not accept " . implode(', ', $unknown) . ' (the gateway rejects unknown fields).'
            );
        }

        $missing = array_values(array_filter(
            $required,
            static fn (string $key): bool => !array_key_exists($key, $plain) || $plain[$key] === ''
        ));
        if ($missing !== []) {
            throw new ConfigurationException("{$label} requires " . implode(', ', $missing) . '.');
        }

        return $plain;
    }

    /**
     * @param string[]            $allowed
     * @param array<string,mixed> $filters
     * @return array<string,scalar>
     */
    private static function pickQuery(string $routeName, array $allowed, array $filters): array
    {
        $query = [];
        $unknown = [];
        foreach ($filters as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (!in_array((string) $key, $allowed, true)) {
                $unknown[] = (string) $key;
                continue;
            }
            if (is_bool($value)) {
                // `true`/`false`, not PHP's `1`/``: the gateway parses the literal words.
                $value = $value ? 'true' : 'false';
            } elseif (!is_scalar($value)) {
                throw new ConfigurationException("{$routeName} filter '{$key}' must be a scalar.");
            }
            $query[(string) $key] = $value;
        }
        if ($unknown !== []) {
            throw new ConfigurationException("{$routeName} does not accept the filter(s) " . implode(', ', $unknown) . '.');
        }

        // Sent in the route's declared order, independent of the caller's.
        $ordered = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $query)) {
                $ordered[$key] = $query[$key];
            }
        }

        return $ordered;
    }

    /**
     * Minor units only. A non-negative integer is sent as its decimal string;
     * a float is refused rather than rounded.
     *
     * @param mixed $value
     */
    private static function requireAmount(string $label, $value): string
    {
        $text = is_int($value) && $value >= 0 ? (string) $value : $value;
        if (!is_string($text) || preg_match(WalletRoutes::AMOUNT_PATTERN, $text) !== 1) {
            throw new ConfigurationException(sprintf(
                '%s must be minor units as a digit string (e.g. "12550" for 125.50); got %s.',
                $label,
                (string) json_encode($value, JSON_PRESERVE_ZERO_FRACTION)
            ));
        }

        return $text;
    }

    private static function requireIdempotencyKey(string $routeName, ?string $key): string
    {
        $value = trim((string) $key);
        if ($value === '') {
            throw new ConfigurationException(
                "A stable Idempotency-Key is required for {$routeName}. Reuse the same key when retrying the same operation."
            );
        }
        $pattern = '/^[^\x00-\x1F\x7F]{1,' . WalletRoutes::IDEMPOTENCY_KEY_MAX . '}$/u';
        if (preg_match($pattern, $value) !== 1) {
            throw new ConfigurationException(sprintf(
                'Idempotency-Key must be 1-%d characters without control characters.',
                WalletRoutes::IDEMPOTENCY_KEY_MAX
            ));
        }

        return $value;
    }

    /**
     * `array_is_list()` equivalent (the built-in needs PHP 8.1; this package
     * supports 7.4).
     *
     * @param array<mixed> $value
     */
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
