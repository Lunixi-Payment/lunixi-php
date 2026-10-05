<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Wallet;

use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Http\Envelope;

/**
 * Wallet programs of the signing merchant (`$lunixi->wallet()->programs`).
 * Reads need `wallet:program:read`; the collection anchor `wallet:program:manage`.
 */
final class WalletPrograms
{
    private WalletInvoker $invoker;

    public function __construct(WalletInvoker $invoker)
    {
        $this->invoker = $invoker;
    }

    /**
     * The wallet programs of the signing merchant.
     *
     * @throws ApiException
     */
    public function listMine(): WalletPage
    {
        return new WalletPage($this->invoker->call('listMyPrograms'));
    }

    /**
     * Sets the end user whose wallet collects the program's merchant payments.
     * Omitting `collectionEndUserId` clears the anchor.
     *
     * @param array{collectionEndUserId?:string, reason?:string, expectedVersion?:int} $request
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function setCollectionAnchor(string $programId, array $request = []): array
    {
        return Envelope::data($this->invoker->call('setCollectionAnchor', ['programId' => $programId], $request));
    }
}
