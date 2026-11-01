<?php

declare(strict_types=1);

namespace Lunixi\Sdk\PhysicalPos;

/**
 * Physical POS webhook event types (subscribe a PHYSICAL_POS endpoint to
 * `physical_pos.*`). Delivered in the same signed envelope as every Lunixi
 * webhook: verify with WebhookVerifier, then read it with
 * PhysicalPosClient::parseWebhookEvent().
 *
 * Reversals (void, refund, close) use the same payment events; tell them apart
 * by `operation` and `rootPaymentId`, and the original payment's source by
 * `original.origin` (PhysicalPosWebhookEvent::original()).
 *
 * PAYMENT_DEVICE_SALE reports a sale made directly on the device
 * (`origin: DEVICE_DIRECT`): it arrives approved with `deviceOccurredAt`; no
 * `approved` event is sent for it. Its refund comes with the usual payment
 * events (`original.origin` = `DEVICE_DIRECT`). Delivery is at-least-once and unordered:
 * dedupe on the event id and drop an event whose `historySeq` is not greater
 * than the last one you applied for that payment.
 */
final class PhysicalPosEvents
{
    public const PAYMENT_APPROVED = 'physical_pos.payment.approved';
    public const PAYMENT_PARTIALLY_APPROVED = 'physical_pos.payment.partially_approved';
    public const PAYMENT_DECLINED = 'physical_pos.payment.declined';
    public const PAYMENT_CANCELLED = 'physical_pos.payment.cancelled';
    /** Never started: timed out, or cancelled by you before it reached the device. */
    public const PAYMENT_EXPIRED = 'physical_pos.payment.expired';
    public const PAYMENT_FAILED = 'physical_pos.payment.failed';
    /** Outcome unknown: do not charge again, the result is being verified. */
    public const PAYMENT_UNKNOWN = 'physical_pos.payment.unknown';
    public const PAYMENT_MANUAL_REVIEW = 'physical_pos.payment.manual_review';
    /** A sale made directly on the device, recorded already approved. */
    public const PAYMENT_DEVICE_SALE = 'physical_pos.payment.device_sale';

    public const FISCAL_DOCUMENT_ISSUED = 'physical_pos.fiscal_document.issued';
    public const FISCAL_DOCUMENT_FAILED = 'physical_pos.fiscal_document.failed';
    public const FISCAL_DOCUMENT_CANCELLED = 'physical_pos.fiscal_document.cancelled';
    public const FISCAL_DOCUMENT_ACTION_REQUIRED = 'physical_pos.fiscal_document.action_required';

    /** Subscription pattern covering every physical POS event. */
    public const ALL_EVENTS = 'physical_pos.*';

    public const PAYMENT_EVENTS = [
        self::PAYMENT_APPROVED,
        self::PAYMENT_PARTIALLY_APPROVED,
        self::PAYMENT_DECLINED,
        self::PAYMENT_CANCELLED,
        self::PAYMENT_EXPIRED,
        self::PAYMENT_FAILED,
        self::PAYMENT_UNKNOWN,
        self::PAYMENT_MANUAL_REVIEW,
        self::PAYMENT_DEVICE_SALE,
    ];

    public const FISCAL_DOCUMENT_EVENTS = [
        self::FISCAL_DOCUMENT_ISSUED,
        self::FISCAL_DOCUMENT_FAILED,
        self::FISCAL_DOCUMENT_CANCELLED,
        self::FISCAL_DOCUMENT_ACTION_REQUIRED,
    ];

    public const ALL = [
        self::PAYMENT_APPROVED,
        self::PAYMENT_PARTIALLY_APPROVED,
        self::PAYMENT_DECLINED,
        self::PAYMENT_CANCELLED,
        self::PAYMENT_EXPIRED,
        self::PAYMENT_FAILED,
        self::PAYMENT_UNKNOWN,
        self::PAYMENT_MANUAL_REVIEW,
        self::PAYMENT_DEVICE_SALE,
        self::FISCAL_DOCUMENT_ISSUED,
        self::FISCAL_DOCUMENT_FAILED,
        self::FISCAL_DOCUMENT_CANCELLED,
        self::FISCAL_DOCUMENT_ACTION_REQUIRED,
    ];

    private function __construct()
    {
    }
}
