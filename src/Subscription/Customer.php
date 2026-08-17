<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Subscription;

/**
 * A subscription customer (response `data`). `externalId` is the merchant's own
 * key (e.g. a WordPress user id / email) used for idempotent upsert + lookup.
 */
final class Customer
{
    /** @var array<string,mixed> */
    private array $data;

    /** @param array<string,mixed> $data */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function id(): string
    {
        return (string) ($this->data['id'] ?? '');
    }

    public function externalId(): string
    {
        return (string) ($this->data['externalId'] ?? '');
    }

    public function email(): string
    {
        return (string) ($this->data['email'] ?? '');
    }

    public function name(): string
    {
        return (string) ($this->data['name'] ?? '');
    }

    public function defaultPaymentCardId(): ?string
    {
        $value = $this->data['defaultPaymentCardId'] ?? null;
        return (is_string($value) && $value !== '') ? $value : null;
    }

    /** @return mixed */
    public function get(string $key)
    {
        return $this->data[$key] ?? null;
    }

    /** @return array<string,mixed> */
    public function raw(): array
    {
        return $this->data;
    }
}
