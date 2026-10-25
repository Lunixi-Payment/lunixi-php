<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Sanction;

use Lunixi\Sdk\ApiClient;
use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Exception\ConfigurationException;

/**
 * Sends one route of {@see SanctionRoutes::ROUTES}. Used by
 * {@see SanctionClient}; integrations call the client, not this class.
 *
 * The path, body and query are checked against the route before anything
 * leaves the process. Each of these is a ConfigurationException: a blank path
 * id, an unknown body field, a missing required field, none of a route's
 * `anyOf` fields, an unknown nested field, a value outside its set
 * ({@see SanctionRoutes::VALUE_SETS}), an unknown query filter.
 *
 * Sanction routes take the access token minted from the API key; there is no
 * per-request signature.
 *
 * A screening answer that is not `status: "success"` is refused rather than
 * read: a screening that did not run must never look like an empty result.
 * (The gateway answers such a screening with 503, which already throws.)
 *
 * @internal
 */
final class SanctionInvoker
{
    private ApiClient $api;

    public function __construct(ApiClient $api)
    {
        $this->api = $api;
    }

    /**
     * @param array<string,string>     $pathParams Values of the `:name` segments of the path.
     * @param array<string,mixed>|null $body       Body fields; null values are dropped.
     * @param array<string,mixed>|null $query      Query filters; null and empty values are dropped.
     * @return array<string,mixed> The decoded envelope.
     * @throws ConfigurationException before sending, when the call does not fit the route.
     * @throws ApiException
     */
    public function call(string $routeName, array $pathParams = [], ?array $body = null, ?array $query = null): array
    {
        $route = SanctionRoutes::ROUTES[$routeName] ?? null;
        if ($route === null) {
            throw new ConfigurationException("Unknown sanction route '{$routeName}'.");
        }

        $path = self::fillPath($route['path'], $pathParams);
        $payload = $route['method'] === 'GET' ? null : self::checkBody($routeName, $route, $body ?? []);
        $options = [];
        if ($query !== null) {
            $picked = self::pickQuery($routeName, $route['query'] ?? [], $query);
            if ($picked !== []) {
                $options['query'] = $picked;
            }
        }

        $response = $this->api->request($route['method'], $path, $payload, $options);

        if ($route['screening'] && isset($response['status']) && $response['status'] !== 'success') {
            $code = is_string($response['code'] ?? null) ? $response['code'] : null;
            $message = is_string($response['message'] ?? null) && $response['message'] !== ''
                ? $response['message']
                : 'The screening did not complete.';
            $requestId = is_string($response['requestId'] ?? null) ? $response['requestId'] : null;
            throw new ApiException($message, (int) ($response['statusCode'] ?? 0), $code, $response, $requestId);
        }

        return $response;
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
     * @param array<string,mixed> $route
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private static function checkBody(string $routeName, array $route, array $body): array
    {
        $plain = self::fields($routeName, $body, $route['required'] ?? [], $route['optional'] ?? []);

        $anyOf = $route['anyOf'] ?? [];
        if ($anyOf !== []) {
            $present = array_filter($anyOf, static fn (string $key): bool => self::present($plain[$key] ?? null));
            if ($present === []) {
                throw new ConfigurationException("{$routeName} requires at least one of " . implode(', ', $anyOf) . '.');
            }
        }

        foreach ($plain as $key => $value) {
            $label = "{$routeName}.{$key}";
            if (isset(SanctionRoutes::NESTED_FIELDS[$label])) {
                $plain[$key] = self::checkRows($label, SanctionRoutes::NESTED_FIELDS[$label], $value);
            }
            if (isset(SanctionRoutes::VALUE_SETS[$label])) {
                self::checkValues($label, SanctionRoutes::VALUE_SETS[$label], $value);
            }
        }

        return $plain;
    }

    /**
     * @param array{required:string[],optional:string[]} $spec
     * @param mixed $rows
     * @return array<int,array<string,mixed>>
     */
    private static function checkRows(string $label, array $spec, $rows): array
    {
        if (!is_array($rows) || !self::isList($rows)) {
            throw new ConfigurationException("{$label} must be a list.");
        }
        $checked = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row) || ($row !== [] && self::isList($row))) {
                throw new ConfigurationException("{$label}[{$index}] must be an object.");
            }
            $checked[] = self::fields("{$label}[{$index}]", $row, $spec['required'], $spec['optional']);
        }

        return $checked;
    }

    /**
     * @param string[] $allowed
     * @param mixed    $value
     */
    private static function checkValues(string $label, array $allowed, $value): void
    {
        $values = is_array($value) ? $value : [$value];
        $outside = array_filter($values, static fn ($entry): bool => !in_array($entry, $allowed, true));
        if ($outside !== []) {
            throw new ConfigurationException(sprintf(
                '%s must be one of %s; got %s.',
                $label,
                implode(', ', $allowed),
                implode(', ', array_map(static fn ($entry): string => (string) json_encode($entry), $outside))
            ));
        }
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
            throw new ConfigurationException("{$label} does not accept " . implode(', ', $unknown) . '.');
        }

        $missing = array_values(array_filter(
            $required,
            static fn (string $key): bool => !self::present($plain[$key] ?? null)
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
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            if (!in_array((string) $key, $allowed, true)) {
                $unknown[] = (string) $key;
                continue;
            }
            if (is_bool($value)) {
                // `true`/`false`, not PHP's `1`/``: the gateway parses the literal words.
                $value = $value ? 'true' : 'false';
            } elseif (is_array($value)) {
                // A merged match's member ids: comma-separated (the gateway splits them).
                $value = implode(',', array_map('strval', array_filter($value, 'is_scalar')));
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

    /** @param mixed $value */
    private static function present($value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        return !is_array($value) || $value !== [];
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
