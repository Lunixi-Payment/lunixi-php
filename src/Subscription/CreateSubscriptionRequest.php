<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Subscription;

use Lunixi\Sdk\Exception\ConfigurationException;

/**
 * Body for `POST /api/v1/subscriptions/subscriptions`.
 *
 * Required: customerId, planId and an idempotencyKey (the gateway requires it
 * to make subscription creation safe to retry — pass a stable, per-intent key).
 * Provide a payment instrument (paymentCardId or paymentMethodId) for an
 * AUTO_CHARGE subscription.
 *
 *   $req = (new CreateSubscriptionRequest($customerId, $planId, $idem))
 *       ->withPaymentCardId($cardId)
 *       ->withCollectionMethod(CollectionMethod::AUTO_CHARGE);
 */
final class CreateSubscriptionRequest
{
    private string $customerId;
    private string $planId;
    private string $idempotencyKey;

    /** @var array<string,mixed> */
    private array $optional = [];

    public function __construct(string $customerId, string $planId, string $idempotencyKey)
    {
        if (trim($customerId) === '') {
            throw new ConfigurationException("CreateSubscriptionRequest 'customerId' is required.");
        }
        if (trim($planId) === '') {
            throw new ConfigurationException("CreateSubscriptionRequest 'planId' is required.");
        }
        if (trim($idempotencyKey) === '') {
            throw new ConfigurationException("CreateSubscriptionRequest 'idempotencyKey' is required.");
        }
        $this->customerId = $customerId;
        $this->planId = $planId;
        $this->idempotencyKey = $idempotencyKey;
    }

    public function idempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function withPaymentCardId(string $cardId): self
    {
        $this->optional['paymentCardId'] = $cardId;
        return $this;
    }

    public function withPaymentMethodId(string $paymentMethodId): self
    {
        $this->optional['paymentMethodId'] = $paymentMethodId;
        return $this;
    }

    public function withCollectionMethod(string $method): self
    {
        if (!in_array($method, CollectionMethod::ALL, true)) {
            throw new ConfigurationException("Unsupported collectionMethod '{$method}'.");
        }
        $this->optional['collectionMethod'] = $method;
        return $this;
    }

    public function withPriceUpdatePolicy(string $policy): self
    {
        $this->optional['priceUpdatePolicy'] = $policy;
        return $this;
    }

    public function withEntitlementPolicy(string $policy): self
    {
        $this->optional['entitlementPolicy'] = $policy;
        return $this;
    }

    public function withBillingTimezone(string $ianaTimezone): self
    {
        $this->optional['billingTimezone'] = $ianaTimezone;
        return $this;
    }

    /** ISO-8601 instant; future-dates the subscription (status PENDING until then). */
    public function withStartsAt(string $iso8601): self
    {
        $this->optional['startsAt'] = $iso8601;
        return $this;
    }

    /** Force a first charge on the next billing tick instead of waiting for the first occurrence. */
    public function withChargeOnCreate(bool $chargeOnCreate): self
    {
        $this->optional['chargeOnCreate'] = $chargeOnCreate;
        return $this;
    }

    public function withBillingCycleAnchorOverride(string $iso8601): self
    {
        $this->optional['billingCycleAnchorOverride'] = $iso8601;
        return $this;
    }

    public function withSkipTrial(bool $skipTrial): self
    {
        $this->optional['skipTrial'] = $skipTrial;
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
            'customerId' => $this->customerId,
            'planId' => $this->planId,
            'idempotencyKey' => $this->idempotencyKey,
        ], $this->optional);
    }
}
