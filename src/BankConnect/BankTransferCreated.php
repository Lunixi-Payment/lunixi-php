<?php

declare(strict_types=1);

namespace Lunixi\Sdk\BankConnect;

/**
 * Answer of BankTransferClient::create() (HTTP 201).
 */
final class BankTransferCreated extends BankConnectResource
{
    public function transfer(): BankTransfer
    {
        return new BankTransfer($this->object('transfer'));
    }

    /** True when the same Idempotency-Key and body answered an existing transfer. */
    public function replayed(): bool
    {
        return $this->bool('replayed');
    }

    /**
     * Dashboard e-mail code challenge; always null for an API-key call.
     *
     * @return array<string,mixed>|null
     */
    public function stepUp(): ?array
    {
        $value = $this->data['stepUp'] ?? null;
        return is_array($value) ? $value : null;
    }
}
