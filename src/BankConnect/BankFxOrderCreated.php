<?php

declare(strict_types=1);

namespace Lunixi\Sdk\BankConnect;

/**
 * Answer of BankFxClient::createOrder() (HTTP 201).
 */
final class BankFxOrderCreated extends BankConnectResource
{
    public function order(): BankFxOrder
    {
        return new BankFxOrder($this->object('order'));
    }

    /** True when the same Idempotency-Key and body answered an existing order. */
    public function replayed(): bool
    {
        return $this->bool('replayed');
    }
}
