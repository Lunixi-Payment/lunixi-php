<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Insurance;

use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Webhook\WebhookEvent;

/**
 * The `data` of a verified `insurance.*` order webhook. No other field is delivered
 * and it carries no personal data.
 *
 *     $event = $lunixi->webhooks()->verify($rawBody, $headers, $secret);
 *     if (in_array($event->type(), InsuranceEvents::ORDER_EVENTS, true)) {
 *         $order = InsuranceWebhookData::fromEvent($event); // dedupe on $event->id()
 *     }
 *
 * Decide on the order from the webhook; an API read only shows the current
 * state. getOrder($order->insuranceOrderId()) returns the full order.
 */
final class InsuranceWebhookData extends InsuranceResource
{
    private string $environment;

    /**
     * @param array<string,mixed> $data        The event `data`.
     * @param string              $environment `TEST` or `LIVE` (the delivery envelope's `environment`).
     */
    public function __construct(array $data, string $environment = '')
    {
        parent::__construct($data);
        $this->environment = $environment;
    }

    /**
     * @throws ConfigurationException When the event is not an insurance order event (a lead
     *   event is read with InsuranceLeadWebhookData::fromEvent()).
     */
    public static function fromEvent(WebhookEvent $event): self
    {
        if (!in_array($event->type(), InsuranceEvents::ORDER_EVENTS, true)) {
            throw new ConfigurationException(sprintf(
                "Expected an insurance order event (%s), got '%s'.",
                implode(', ', InsuranceEvents::ORDER_EVENTS),
                $event->type()
            ));
        }
        $environment = $event->envelope()['environment'] ?? '';

        return new self($event->data(), is_string($environment) ? $environment : '');
    }

    /** `TEST` or `LIVE`. */
    public function environment(): string
    {
        return $this->environment;
    }

    /** Same as the envelope's id; dedupe on it. */
    public function eventId(): string
    {
        return $this->str('eventId');
    }

    /** One of InsuranceEvents::ORDER_EVENTS. */
    public function eventType(): string
    {
        return $this->str('eventType');
    }

    public function merchantId(): string
    {
        return $this->str('merchantId');
    }

    /** `inso_…`. */
    public function insuranceOrderId(): string
    {
        return $this->str('insuranceOrderId');
    }

    public function paymentIntentId(): ?string
    {
        return $this->strOrNull('paymentIntentId');
    }

    public function merchantOrderId(): ?string
    {
        return $this->strOrNull('merchantOrderId');
    }

    public function insuranceType(): string
    {
        return $this->str('insuranceType');
    }

    /** One of the InsurancePremiumCollectedBy constants. */
    public function premiumCollectedBy(): string
    {
        return $this->str('premiumCollectedBy');
    }

    public function provider(): InsuranceProvider
    {
        return new InsuranceProvider($this->object('provider'));
    }

    /** One of the InsuranceOrderStatus constants. */
    public function status(): string
    {
        return $this->str('status');
    }

    /** One of the InsuranceCollectionStatus constants. */
    public function collectionStatus(): string
    {
        return $this->str('collectionStatus');
    }

    /** One of the InsurancePolicyStatus constants. */
    public function policyStatus(): string
    {
        return $this->str('policyStatus');
    }

    /** One of the InsuranceRefundStatus constants. */
    public function refundStatus(): string
    {
        return $this->str('refundStatus');
    }

    /** One of the InsuranceDocumentStatus constants. */
    public function documentStatus(): string
    {
        return $this->str('documentStatus');
    }

    /** Minor units. */
    public function premiumAmount(): int
    {
        return $this->int('premiumAmount');
    }

    public function currency(): string
    {
        return $this->str('currency');
    }

    /** @return InsuranceWebhookPolicy[] */
    public function policies(): array
    {
        return $this->rows('policies', static fn (array $row): InsuranceWebhookPolicy => new InsuranceWebhookPolicy($row));
    }

    /**
     * Lunixi error code (`insurance.<area>.<reason>`); set only on
     * `insurance.policy.failed` and `insurance.refund.required`.
     */
    public function errorCode(): ?string
    {
        return $this->strOrNull('errorCode');
    }

    public function createdAt(): string
    {
        return $this->str('createdAt');
    }
}
