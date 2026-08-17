<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Marketplace;

/**
 * A sub-dealer record (response `data`).
 */
final class Dealer
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

    public function legalName(): string
    {
        return (string) ($this->data['legalName'] ?? '');
    }

    /** One of DealerStatus::* */
    public function status(): string
    {
        return (string) ($this->data['status'] ?? '');
    }

    public function onboardingStage(): string
    {
        return (string) ($this->data['onboardingStage'] ?? '');
    }

    public function dealerType(): string
    {
        return (string) ($this->data['dealerType'] ?? '');
    }

    public function externalRef(): string
    {
        return (string) ($this->data['externalRef'] ?? '');
    }

    public function isActive(): bool
    {
        return $this->status() === DealerStatus::ACTIVE;
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
