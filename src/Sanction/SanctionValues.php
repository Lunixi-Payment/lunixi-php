<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Sanction;

/**
 * Typed reads of the loosely typed screening payloads. Shared by the result
 * classes of this namespace.
 *
 * @internal
 */
final class SanctionValues
{
    private function __construct()
    {
    }

    /** @param array<string,mixed> $data */
    public static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Null for a missing, null or empty value (the gateway sends `null` or an
     * empty string for "not reported").
     *
     * @param array<string,mixed> $data
     */
    public static function nullableString(array $data, string $key): ?string
    {
        $value = self::string($data, $key);

        return $value === '' ? null : $value;
    }

    /** @param array<string,mixed> $data */
    public static function nullableInt(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;
        if (is_int($value)) {
            return $value === 0 ? null : $value;
        }
        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int) $value === 0 ? null : (int) $value;
        }

        return null;
    }

    /**
     * @param array<string,mixed> $data
     * @return string[]
     */
    public static function stringList(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_map('strval', array_filter($value, 'is_scalar')));
    }

    /**
     * @param array<string,mixed> $data
     * @return array<int,array<string,mixed>>
     */
    public static function rows(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    /**
     * @param array<string,mixed> $data
     * @return SanctionMatchList[]
     */
    public static function matchLists(array $data): array
    {
        return array_map(
            static fn (array $row): SanctionMatchList => new SanctionMatchList($row),
            self::rows($data, 'lists')
        );
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function json(array $data, string $objectKey, string $jsonKey): array
    {
        $value = $data[$objectKey] ?? null;
        if (is_array($value)) {
            return $value;
        }
        $json = $data[$jsonKey] ?? null;
        if (is_string($json) && $json !== '') {
            $decoded = json_decode($json, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
