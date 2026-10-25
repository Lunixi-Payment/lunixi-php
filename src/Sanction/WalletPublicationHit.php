<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Sanction;

/**
 * A listed party in the active list publication that holds the screened
 * address. A party on several lists is one hit, with all its lists in
 * {@see lists()}.
 */
final class WalletPublicationHit
{
    /** @var array<string,mixed> */
    private array $data;

    /** @param array<string,mixed> $data */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function hitId(): string
    {
        return SanctionValues::string($this->data, 'hitId');
    }

    public function entityId(): string
    {
        return SanctionValues::string($this->data, 'entityId');
    }

    public function entityName(): string
    {
        return SanctionValues::string($this->data, 'entityName');
    }

    /** PERSON | ORGANIZATION | VESSEL | AIRCRAFT | OTHER */
    public function entityType(): string
    {
        return SanctionValues::string($this->data, 'entityType');
    }

    public function hitType(): string
    {
        return SanctionValues::string($this->data, 'hitType');
    }

    /** 100 when the party is in the sanctions domain, 90 for any other domain (review). */
    public function score(): float
    {
        return (float) ($this->data['score'] ?? 0);
    }

    /** The party's strictest risk domain. */
    public function riskCategory(): string
    {
        return SanctionValues::string($this->data, 'riskCategory');
    }

    /** @return string[] */
    public function riskDomains(): array
    {
        return SanctionValues::stringList($this->data, 'riskDomains');
    }

    public function sourceListName(): string
    {
        return SanctionValues::string($this->data, 'sourceListName');
    }

    /** @return SanctionMatchList[] */
    public function lists(): array
    {
        return SanctionValues::matchLists($this->data);
    }

    /** The address as the list writes it. */
    public function listedAddress(): string
    {
        return SanctionValues::string($this->data, 'listedAddress');
    }

    /** @return string[] Network or currency labels the list states (e.g. ETH, XBT, TRX). */
    public function listedChains(): array
    {
        return SanctionValues::stringList($this->data, 'listedChains');
    }

    /** The comparison rule applied: EVM_HEX | BECH32 | CASHADDR | CASE_SENSITIVE. */
    public function caseRule(): string
    {
        return SanctionValues::string($this->data, 'caseRule');
    }

    /** @return array<string,mixed> */
    public function raw(): array
    {
        return $this->data;
    }
}
