<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Kyc;

/**
 * Catalogue of KYC/KYB/screening webhook event types (same signed envelope as
 * payment/subscription webhooks). The `data` payload always carries `sessionId`
 * + `externalCustomerId` and, on terminal events, the decision.
 *
 * The rescreen / sanctions events are what make verification a LIFECYCLE: a hit
 * on an already-APPROVED session must revoke access.
 */
final class KycEvents
{
    // kyc.* (person)
    public const SESSION_CREATED = 'kyc.session.created';
    public const SESSION_INITIATED = 'kyc.session.initiated';
    public const SESSION_EXPIRED = 'kyc.session.expired';
    public const SESSION_ABANDONED = 'kyc.session.abandoned';
    public const SESSION_CANCELLED = 'kyc.session.cancelled';
    public const DOCUMENT_RECEIVED = 'kyc.document.received';
    public const DOCUMENT_REJECTED = 'kyc.document.rejected';
    public const LIVENESS_COMPLETED = 'kyc.liveness.completed';
    public const PROCESSING_STARTED = 'kyc.processing.started';
    public const MANUAL_REVIEW_REQUIRED = 'kyc.manual_review.required';
    public const APPROVED = 'kyc.approved';
    public const REJECTED = 'kyc.rejected';
    public const RESCREEN_REQUIRED = 'kyc.rescreen.required';

    // kyb.* (company)
    public const KYB_SESSION_CREATED = 'kyb.session.created';
    public const KYB_COMPANY_VERIFIED = 'kyb.company.verified';
    public const KYB_COMPANY_NOT_FOUND = 'kyb.company.not_found';
    public const KYB_UBO_PENDING = 'kyb.ubo.pending';
    public const KYB_RELATED_KYC_REQUIRED = 'kyb.related_kyc.required';
    public const KYB_RELATED_KYC_COMPLETED = 'kyb.related_kyc.completed';
    public const KYB_MANUAL_REVIEW_REQUIRED = 'kyb.manual_review.required';
    public const KYB_APPROVED = 'kyb.approved';
    public const KYB_REJECTED = 'kyb.rejected';

    // screening.* / monitoring (AML)
    public const SCREENING_MATCH_DETECTED = 'screening.match.detected';
    public const SCREENING_FALSE_POSITIVE_CLEARED = 'screening.false_positive.cleared';
    public const MONITORING_ALERT_CREATED = 'ongoing_monitoring.alert.created';

    private function __construct()
    {
    }
}
