<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Kyc;

/**
 * Purpose of an EVIDENCE session (mirrors the kyc-service evidence purposes).
 */
final class EvidencePurpose
{
    public const IDENTITY_VERIFICATION = 'IDENTITY_VERIFICATION';
    public const BUSINESS_VERIFICATION = 'BUSINESS_VERIFICATION';
    public const PROOF_OF_FUNDS = 'PROOF_OF_FUNDS';
    public const PROOF_OF_ADDRESS = 'PROOF_OF_ADDRESS';
    public const SOURCE_OF_FUNDS = 'SOURCE_OF_FUNDS';
    public const SOURCE_OF_WEALTH = 'SOURCE_OF_WEALTH';
    public const TRANSACTION_EVIDENCE = 'TRANSACTION_EVIDENCE';
    public const CARD_OWNERSHIP = 'CARD_OWNERSHIP';
    public const PAYMENT_METHOD_OWNERSHIP = 'PAYMENT_METHOD_OWNERSHIP';
    public const PAYOUT_EVIDENCE = 'PAYOUT_EVIDENCE';
    public const MERCHANT_ONBOARDING = 'MERCHANT_ONBOARDING';

    public const ALL = [
        self::IDENTITY_VERIFICATION, self::BUSINESS_VERIFICATION, self::PROOF_OF_FUNDS,
        self::PROOF_OF_ADDRESS, self::SOURCE_OF_FUNDS, self::SOURCE_OF_WEALTH,
        self::TRANSACTION_EVIDENCE, self::CARD_OWNERSHIP, self::PAYMENT_METHOD_OWNERSHIP,
        self::PAYOUT_EVIDENCE, self::MERCHANT_ONBOARDING,
    ];

    private function __construct()
    {
    }
}
