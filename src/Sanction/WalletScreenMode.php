<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Sanction;

/**
 * Wallet-screening depth: the wider modes add exposure and crypto-intelligence
 * hits to the list-publication lookup.
 */
final class WalletScreenMode
{
    public const WALLET_SANCTIONS = 'wallet_sanctions';
    public const WALLET_RISK = 'wallet_risk';
    public const WALLET_FULL_AML = 'wallet_full_aml';

    public const ALL = [self::WALLET_SANCTIONS, self::WALLET_RISK, self::WALLET_FULL_AML];

    private function __construct()
    {
    }
}
