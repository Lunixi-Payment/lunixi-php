<?php

declare(strict_types=1);

namespace Lunixi\Sdk\PhysicalPos;

/**
 * One bank line of a device's end-of-day (settlement): what the device
 * reported, what Lunixi recorded and the difference (reported − system,
 * may be negative). `UNMAPPED` lines (bank code not recognised) are not
 * compared.
 */
final class PhysicalPosSettlementLine extends PhysicalPosResource
{
    /** The Lunixi end-of-day command; null for an end-of-day started on the device. */
    public function commandId(): ?string
    {
        return $this->strOrNull('commandId');
    }

    /** The device's own end-of-day id; null for an end-of-day taken with a command. */
    public function settlementRef(): ?string
    {
        return $this->strOrNull('settlementRef');
    }

    /** The device's line number (may have gaps). */
    public function lineNo(): int
    {
        return $this->int('lineNo');
    }

    public function deviceId(): string
    {
        return $this->str('deviceId');
    }

    public function acquirerCode(): ?string
    {
        return $this->strOrNull('acquirerCode');
    }

    /** The bank code the device reported, as is (also on `UNMAPPED` lines). */
    public function acquirerRefRaw(): ?string
    {
        return $this->strOrNull('acquirerRefRaw');
    }

    /** The bank's display name; null when the bank was not recognised. */
    public function acquirerName(): ?string
    {
        return $this->strOrNull('acquirerName');
    }

    /** The device's current display name. */
    public function deviceName(): ?string
    {
        return $this->strOrNull('deviceName');
    }

    public function batchNo(): ?string
    {
        return $this->strOrNull('batchNo');
    }

    public function bankTerminalNumber(): ?string
    {
        return $this->strOrNull('bankTerminalNumber');
    }

    public function currency(): string
    {
        return $this->str('currency');
    }

    /** `MATCHED`, `MISMATCH` or `UNMAPPED`. */
    public function lineStatus(): string
    {
        return $this->str('lineStatus');
    }

    /** @return array<string,int|string> saleCount, saleMinor, refundCount, refundMinor, voidCount, voidMinor */
    public function reported(): array
    {
        return $this->object('reported');
    }

    /** @return array<string,int|string> Same keys as reported(). */
    public function system(): array
    {
        return $this->object('system');
    }

    /** @return array<string,int|string> reported − system per key; may be negative. */
    public function difference(): array
    {
        return $this->object('difference');
    }

    public function periodFrom(): string
    {
        return $this->str('periodFrom');
    }

    public function periodTo(): string
    {
        return $this->str('periodTo');
    }

    public function deviceClosedAt(): ?string
    {
        return $this->strOrNull('deviceClosedAt');
    }

    public function recordedAt(): string
    {
        return $this->str('recordedAt');
    }
}
