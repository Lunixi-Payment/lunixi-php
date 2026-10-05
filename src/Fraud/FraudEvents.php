<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Fraud;

/**
 * Catalogue of fraud webhook event types a merchant endpoint can subscribe to.
 *
 * These are the FRAUD_SERVICE keys of the platform webhook catalogue
 * (organization-service `webhook-event-catalog.ts`), delivered in the same signed
 * envelope as payment/subscription webhooks and verified with
 * {@see \Lunixi\Sdk\Webhook\WebhookVerifier} (v2 signature).
 *
 * Fraud has no webhook system of its own — every merchant-facing fraud event goes
 * through the platform channel. Internal Kafka topic names (for example the
 * `*.v1` decision and shadow topics) are NOT webhook event types and are not
 * deliverable to a merchant endpoint.
 */
final class FraudEvents
{
    /** Async flow result is ready; correlate with `decisionRequestId`. Advisory — it does not change the synchronous decision. */
    public const FLOW_ASYNC_COMPLETED = 'fraud.flow.async_completed';

    /** A "Platform Event" node in your flow fired; `label` and `data` are the values you defined in the flow. */
    public const FLOW_ACTION_TRIGGERED = 'fraud.flow.action_triggered';

    /** Fraud API quota threshold reached, or a request was rejected with 429. Sent once per day and lane. */
    public const QUOTA_THRESHOLD_REACHED = 'fraud.quota.threshold_reached';

    /** One of your fraud alert rules fired. */
    public const ALERT_TRIGGERED = 'fraud.alert.triggered';

    /** An outage affecting fraud evaluation was detected or declared. */
    public const SERVICE_DEGRADED = 'fraud.service.degraded';

    /** The outage closed. Carries the outage duration and how many decisions could not be checked. */
    public const SERVICE_RECOVERED = 'fraud.service.recovered';

    /**
     * A scheduled report you configured finished its period and was produced.
     *
     * The event carries NO download link: download links are short-lived and are
     * produced from the console. Reports you request manually do not send it.
     */
    public const REPORT_READY = 'fraud.report.ready';

    /**
     * Every fraud webhook event type, in catalogue order.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::FLOW_ASYNC_COMPLETED,
            self::FLOW_ACTION_TRIGGERED,
            self::QUOTA_THRESHOLD_REACHED,
            self::ALERT_TRIGGERED,
            self::SERVICE_DEGRADED,
            self::SERVICE_RECOVERED,
            self::REPORT_READY,
        ];
    }

    private function __construct()
    {
    }
}
