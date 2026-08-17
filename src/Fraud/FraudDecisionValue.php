<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Fraud;

/**
 * Fraud decision outcomes (mirrors the fraud-service FraudDecisionValue enum).
 * In the payment flow the gateway maps these to actions automatically:
 *   allow → proceed · force_3d → force 3-D Secure · review → step-up/hold ·
 *   block → decline.
 */
final class FraudDecisionValue
{
    public const ALLOW = 'allow';
    public const REVIEW = 'review';
    public const BLOCK = 'block';
    public const FORCE_3D = 'force_3d';

    public const ALL = [self::ALLOW, self::REVIEW, self::BLOCK, self::FORCE_3D];

    private function __construct()
    {
    }
}
