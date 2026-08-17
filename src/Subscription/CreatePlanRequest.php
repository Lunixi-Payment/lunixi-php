<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Subscription;

use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Payment\Currency;

/**
 * Body for `POST /api/v1/subscriptions/plans`. `price` is in MINOR units.
 *
 *   $req = (new CreatePlanRequest($productId, 'pro-monthly', 'Pro', 9900, 'TRY',
 *              new BillingRule('MONTHLY', 1)))
 *       ->withTrialPeriodDays(14);
 */
final class CreatePlanRequest
{
    private string $productId;
    private string $code;
    private string $name;
    private int $price;
    private string $currency;
    private BillingRule $billing;

    /** @var array<string,mixed> */
    private array $optional = [];

    public function __construct(
        string $productId,
        string $code,
        string $name,
        int $price,
        string $currency,
        BillingRule $billing
    ) {
        if (trim($productId) === '') {
            throw new ConfigurationException("CreatePlanRequest 'productId' is required.");
        }
        if (trim($code) === '') {
            throw new ConfigurationException("CreatePlanRequest 'code' is required.");
        }
        if (trim($name) === '') {
            throw new ConfigurationException("CreatePlanRequest 'name' is required.");
        }
        if ($price < 0) {
            throw new ConfigurationException("CreatePlanRequest 'price' must be a non-negative integer (minor units).");
        }
        $currency = strtoupper($currency);
        if (!Currency::isSupported($currency)) {
            throw new ConfigurationException("Unsupported currency '{$currency}'.");
        }

        $this->productId = $productId;
        $this->code = $code;
        $this->name = $name;
        $this->price = $price;
        $this->currency = $currency;
        $this->billing = $billing;
    }

    public function withDescription(string $description): self
    {
        $this->optional['description'] = $description;
        return $this;
    }

    public function withTrialPeriodDays(int $days): self
    {
        $this->optional['trialPeriodDays'] = max(0, $days);
        return $this;
    }

    public function withMaxDunningAttempts(int $attempts): self
    {
        $this->optional['maxDunningAttempts'] = max(0, $attempts);
        return $this;
    }

    /** @param int[] $days */
    public function withDunningScheduleDays(array $days): self
    {
        $this->optional['dunningScheduleDays'] = array_values(array_map('intval', $days));
        return $this;
    }

    public function withGracePeriodDays(int $days): self
    {
        $this->optional['gracePeriodDays'] = max(0, $days);
        return $this;
    }

    /** @param array<string,mixed> $metadata */
    public function withMetadata(array $metadata): self
    {
        $this->optional['metadata'] = $metadata;
        return $this;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return array_merge([
            'productId' => $this->productId,
            'code' => $this->code,
            'name' => $this->name,
            'price' => $this->price,
            'currency' => $this->currency,
            'billing' => $this->billing->toArray(),
        ], $this->optional);
    }
}
