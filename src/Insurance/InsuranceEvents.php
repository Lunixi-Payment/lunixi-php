<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Insurance;

/**
 * Insurance webhook event types (subscribe an INSURANCE_SERVICE endpoint to
 * `insurance.*`), delivered in the same signed envelope as every Lunixi
 * webhook and verified with WebhookVerifier. Read an order event with
 * InsuranceWebhookData::fromEvent() and a lead event with
 * InsuranceLeadWebhookData::fromEvent(). Delivery is at-least-once: dedupe on the
 * event id (`data.eventId` carries the same value).
 */
final class InsuranceEvents
{
    /** The order's policies were issued. */
    public const POLICY_ISSUED = 'insurance.policy.issued';

    /** The policy could not be issued or the premium was not collected; `errorCode` says why. */
    public const POLICY_FAILED = 'insurance.policy.failed';

    /** The collected premium has to be refunded; `errorCode` says why. */
    public const REFUND_REQUIRED = 'insurance.refund.required';

    /** A policy document can be downloaded (`policies[].documentUrl`, a signed API call). */
    public const POLICY_DOCUMENT_READY = 'insurance.policy.document_ready';

    /** The provider confirmed the cancellation. */
    public const POLICY_CANCELLED = 'insurance.policy.cancelled';

    /** The premium refund completed. */
    public const REFUND_COMPLETED = 'insurance.refund.completed';

    /** The insurance provider received a lead; read it with InsuranceLeadWebhookData::fromEvent(). */
    public const LEAD_SUBMITTED = 'insurance.lead.submitted';

    /** Subscription pattern covering every insurance event. */
    public const ALL_EVENTS = 'insurance.*';

    /** The events about an order (InsuranceWebhookData). */
    public const ORDER_EVENTS = [
        self::POLICY_ISSUED,
        self::POLICY_FAILED,
        self::REFUND_REQUIRED,
        self::POLICY_DOCUMENT_READY,
        self::POLICY_CANCELLED,
        self::REFUND_COMPLETED,
    ];

    /** The events about a lead (InsuranceLeadWebhookData). */
    public const LEAD_EVENTS = [
        self::LEAD_SUBMITTED,
    ];

    /** Every insurance event: the order events, then the lead events. */
    public const ALL = [
        self::POLICY_ISSUED,
        self::POLICY_FAILED,
        self::REFUND_REQUIRED,
        self::POLICY_DOCUMENT_READY,
        self::POLICY_CANCELLED,
        self::REFUND_COMPLETED,
        self::LEAD_SUBMITTED,
    ];

    private function __construct()
    {
    }
}
