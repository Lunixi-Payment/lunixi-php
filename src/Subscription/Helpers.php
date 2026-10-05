<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Subscription;

/**
 * Small shared helpers for the subscription clients: extracting the response
 * `data` object and normalising query filters (drop empties, stringify bools so
 * the gateway receives `true`/`false` rather than `1`/``).
 *
 * @internal
 */
final class Helpers
{
    /**
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    public static function dataOf(array $response): array
    {
        return isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,scalar>
     */
    public static function query(array $filters): array
    {
        $out = [];
        foreach ($filters as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (is_bool($value)) {
                $out[$key] = $value ? 'true' : 'false';
                continue;
            }
            $out[$key] = $value;
        }
        return $out;
    }

    private function __construct()
    {
    }
}
