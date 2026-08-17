<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Subscription;

/**
 * Canonical subscription status values (mirrors the subscription-service
 * SubscriptionStatus enum).
 */
final class SubscriptionStatus
{
    public const PENDING = 'PENDING';       // starts_at is in the future
    public const TRIALING = 'TRIALING';     // trial period active
    public const ACTIVE = 'ACTIVE';         // billing normally
    public const PAST_DUE = 'PAST_DUE';     // a charge failed; dunning ongoing
    public const UNPAID = 'UNPAID';         // dunning exhausted; grace period
    public const PAUSED = 'PAUSED';         // merchant-paused; no charges
    public const UPGRADED = 'UPGRADED';     // terminal — plan changed up
    public const DOWNGRADED = 'DOWNGRADED'; // terminal — plan changed down
    public const CANCELED = 'CANCELED';     // terminal — cancelled
    public const EXPIRED = 'EXPIRED';       // terminal — recurrence exhausted

    /** States where the subscription is live and may be billed. */
    public const ACTIVE_STATES = [self::TRIALING, self::ACTIVE, self::PAST_DUE];

    /** Terminal states (no further transitions). */
    public const TERMINAL_STATES = [self::UPGRADED, self::DOWNGRADED, self::CANCELED, self::EXPIRED];

    private function __construct()
    {
    }
}
