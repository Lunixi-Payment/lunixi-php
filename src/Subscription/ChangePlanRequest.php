<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Subscription;

use Lunixi\Sdk\Exception\ConfigurationException;

/**
 * Body for `POST /api/v1/subscriptions/subscriptions/{id}/change-plan`.
 * Upgrade/downgrade to another plan, applied IMMEDIATE (with proration) or at
 * NEXT_PERIOD.
 */
final class ChangePlanRequest
{
    public const IMMEDIATE = 'IMMEDIATE';
    public const NEXT_PERIOD = 'NEXT_PERIOD';

    private string $newPlanId;

    /** @var array<string,mixed> */
    private array $optional = [];

    public function __construct(string $newPlanId)
    {
        if (trim($newPlanId) === '') {
            throw new ConfigurationException("ChangePlanRequest 'newPlanId' is required.");
        }
        $this->newPlanId = $newPlanId;
    }

    public function withBehavior(string $behavior): self
    {
        $behavior = strtoupper($behavior);
        if (!in_array($behavior, [self::IMMEDIATE, self::NEXT_PERIOD], true)) {
            throw new ConfigurationException("ChangePlan behavior must be IMMEDIATE or NEXT_PERIOD.");
        }
        $this->optional['behavior'] = $behavior;
        return $this;
    }

    public function withReason(string $reason): self
    {
        $this->optional['reason'] = $reason;
        return $this;
    }

    public function withIdempotencyKey(string $key): self
    {
        $this->optional['idempotencyKey'] = $key;
        return $this;
    }

    public function idempotencyKey(): ?string
    {
        $key = $this->optional['idempotencyKey'] ?? null;
        return (is_string($key) && $key !== '') ? $key : null;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return array_merge(['newPlanId' => $this->newPlanId], $this->optional);
    }
}
