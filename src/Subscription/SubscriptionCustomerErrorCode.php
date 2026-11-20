<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Subscription;

/**
 * Error codes of a missing / invalid subscription customer field
 * (`details.field`, `details.reason`, `details.fields`). Same values as the
 * Node SDK's `SubscriptionCustomerErrorCode`.
 */
final class SubscriptionCustomerErrorCode
{
    public const FIELD_REQUIRED = 'subscription.customer.field_required';
    public const FIELD_INVALID = 'subscription.customer.field_invalid';

    private function __construct()
    {
    }
}
