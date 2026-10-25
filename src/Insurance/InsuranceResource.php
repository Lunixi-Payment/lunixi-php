<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Insurance;

/**
 * Common read helpers of the insurance response objects.
 *
 * The gateway sends `null` for a field without a value (an empty text becomes
 * null), so every optional accessor returns null instead of an empty string.
 * Amounts are integers in minor units; times are ISO-8601 strings with a time
 * zone.
 */
abstract class InsuranceResource
{
    /** @var array<string,mixed> */
    protected array $data;

    /** @param array<string,mixed> $data */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /** @return mixed Any raw field by name, or null when absent. */
    public function get(string $key)
    {
        return $this->data[$key] ?? null;
    }

    /** @return array<string,mixed> */
    public function raw(): array
    {
        return $this->data;
    }

    protected function str(string $key): string
    {
        $value = $this->data[$key] ?? null;
        return is_scalar($value) ? (string) $value : '';
    }

    protected function strOrNull(string $key): ?string
    {
        $value = $this->data[$key] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function int(string $key): int
    {
        $value = $this->data[$key] ?? null;
        return is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) ? (int) $value : 0;
    }

    /** @return array<string,mixed> */
    protected function object(string $key): array
    {
        $value = $this->data[$key] ?? null;
        return is_array($value) ? $value : [];
    }

    /** @return string[] */
    protected function strings(string $key): array
    {
        $value = $this->data[$key] ?? null;
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_map('strval', array_filter($value, 'is_scalar')));
    }

    /**
     * Text per language code, e.g. `['tr' => '…', 'en' => '…']`.
     *
     * @return array<string,string>
     */
    protected function localized(string $key): array
    {
        $out = [];
        foreach ($this->object($key) as $language => $text) {
            if (is_string($text)) {
                $out[(string) $language] = $text;
            }
        }

        return $out;
    }

    /**
     * @template T
     * @param callable(array<string,mixed>):T $factory
     * @return array<int,T>
     */
    protected function rows(string $key, callable $factory): array
    {
        $value = $this->data[$key] ?? null;
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_map(
            static fn (array $row) => $factory($row),
            array_values(array_filter($value, 'is_array'))
        ));
    }
}
