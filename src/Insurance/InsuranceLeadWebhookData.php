<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Insurance;

use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Webhook\WebhookEvent;

/**
 * The `data` of a verified `insurance.lead.*` webhook: a lead summary, not an
 * order. No other field is delivered and it carries no personal data.
 *
 *     $event = $lunixi->webhooks()->verify($rawBody, $headers, $secret);
 *     if (in_array($event->type(), InsuranceEvents::LEAD_EVENTS, true)) {
 *         $lead = InsuranceLeadWebhookData::fromEvent($event)->lead(); // dedupe on $event->id()
 *     }
 *
 * getLead($lead->leadId()) returns the full lead.
 */
final class InsuranceLeadWebhookData extends InsuranceResource
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

    /** @throws ConfigurationException When the event is not an insurance lead event. */
    public static function fromEvent(WebhookEvent $event): self
    {
        if (!in_array($event->type(), InsuranceEvents::LEAD_EVENTS, true)) {
            throw new ConfigurationException(sprintf(
                "Expected an insurance lead event (%s), got '%s'.",
                implode(', ', InsuranceEvents::LEAD_EVENTS),
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

    /** One of InsuranceEvents::LEAD_EVENTS. */
    public function eventType(): string
    {
        return $this->str('eventType');
    }

    public function merchantId(): string
    {
        return $this->str('merchantId');
    }

    public function lead(): InsuranceWebhookLead
    {
        return new InsuranceWebhookLead($this->object('lead'));
    }

    public function createdAt(): string
    {
        return $this->str('createdAt');
    }
}
