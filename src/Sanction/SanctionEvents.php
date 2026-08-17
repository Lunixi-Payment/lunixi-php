<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Sanction;

/**
 * Catalogue of sanction/screening webhook event types (same signed envelope as
 * payment/subscription webhooks). These also fire from KYC's AML screening, so a
 * receiver should reconcile by the carried customer/session reference.
 */
final class SanctionEvents
{
    public const MATCH_DETECTED = 'screening.match.detected';
    public const FALSE_POSITIVE_CLEARED = 'screening.false_positive.cleared';
    public const MONITORING_ALERT_CREATED = 'ongoing_monitoring.alert.created';
    public const MONITORING_CLEAR = 'ongoing_monitoring.clear';

    private function __construct()
    {
    }
}
