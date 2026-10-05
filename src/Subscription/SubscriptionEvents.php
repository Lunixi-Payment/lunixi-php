<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Subscription;

/**
 * Catalogue of subscription/invoice webhook event types delivered to a merchant
 * endpoint (envelope + HMAC signature identical to payment webhooks). `data`
 * payloads carry minor-unit amounts as strings, the same as payment events.
 *
 * NOTE: invoice.* events are emitted by subscription-service; whether they reach
 * the HTTP endpoint depends on the gateway webhook-service consuming the
 * `invoice.events` topic. The subscription module pairs these with a polling
 * reconciliation backstop so a renewal result is never missed.
 */
final class SubscriptionEvents
{
    // subscription.* (lifecycle)
    public const CREATED = 'subscription.created';
    public const ACTIVATED = 'subscription.activated';
    public const TRIAL_WILL_END = 'subscription.trial_will_end';
    public const PAUSED = 'subscription.paused';
    public const RESUMED = 'subscription.resumed';
    public const CANCEL_SCHEDULED = 'subscription.cancel_scheduled';
    public const CANCELED = 'subscription.canceled';
    public const UPGRADED = 'subscription.upgraded';
    public const DOWNGRADED = 'subscription.downgraded';
    public const PLAN_CHANGE_SCHEDULED = 'subscription.plan_change_scheduled';
    public const PENDING_CHANGE_APPLIED = 'subscription.pending_change_applied';
    public const PAYMENT_CARD_SWAPPED = 'subscription.payment_card_swapped';
    public const PAYMENT_METHOD_LOST = 'subscription.payment_method_lost';

    // invoice.* (billing / renewals)
    public const INVOICE_CREATED = 'invoice.created';
    public const INVOICE_PAID = 'invoice.paid';
    public const INVOICE_PAYMENT_FAILED = 'invoice.payment_failed';
    public const INVOICE_VOIDED = 'invoice.voided';

    // card.* (saved payment methods)
    public const CARD_ADDED = 'card.added';
    public const CARD_SET_DEFAULT = 'card.set_default';
    public const CARD_UPDATED = 'card.updated';
    public const CARD_DEACTIVATED = 'card.deactivated';
    public const CARD_EXPIRING_SOON = 'card.expiring_soon';
    public const CARD_EXPIRED = 'card.expired';

    private function __construct()
    {
    }
}
