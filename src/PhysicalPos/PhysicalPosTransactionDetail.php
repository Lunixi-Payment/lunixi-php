<?php

declare(strict_types=1);

namespace Lunixi\Sdk\PhysicalPos;

/**
 * getTransaction(): the whole tree of a root or child payment id with its
 * command / payment / fiscal-document timeline (oldest first; at most the
 * newest 500 rows).
 */
final class PhysicalPosTransactionDetail extends PhysicalPosResource
{
    public function requestedPaymentId(): string
    {
        return $this->str('requestedPaymentId');
    }

    public function root(): PhysicalPosPayment
    {
        return new PhysicalPosPayment($this->object('root'));
    }

    /** @return PhysicalPosPayment[] */
    public function children(): array
    {
        return $this->payments('children');
    }

    public function childrenTruncated(): bool
    {
        return $this->bool('childrenTruncated');
    }

    /**
     * Rows `{kind, paymentId, commandId, commandType, seq, eventType, fromStatus, toStatus,
     * reasonCode, source, actorType, evidenceStrength, occurredAt, recordedAt}`.
     *
     * @return array<int,array<string,mixed>>
     */
    public function timeline(): array
    {
        return $this->list('timeline');
    }

    public function timelineTruncated(): bool
    {
        return $this->bool('timelineTruncated');
    }

    public function storeId(): ?string
    {
        return $this->strOrNull('storeId');
    }

    public function registerId(): ?string
    {
        return $this->strOrNull('registerId');
    }
}
