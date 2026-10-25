<?php

declare(strict_types=1);

namespace Lunixi\Sdk\BankConnect;

use Generator;
use Lunixi\Sdk\ApiClient;
use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Exception\ConfigurationException;

/**
 * Currency exchange between two of your accounts — `$lunixi->bankConnect()->fx()`.
 *
 *   listRates                 GET  /api/v1/bank-connect/fx/rates?connectionId=
 *   createQuote               POST /api/v1/bank-connect/fx/quotes  (201)
 *   createOrder               POST /api/v1/bank-connect/fx/orders  (201, Idempotency-Key)
 *   listOrders / iterateOrders GET /api/v1/bank-connect/fx/orders
 *   getOrder                  GET  /api/v1/bank-connect/fx/orders/{orderId}
 *
 * Available on a connection whose capabilities include FX_EXCHANGE. The two
 * accounts are active accounts of the same connection in different
 * currencies. Same scope (`bank-connect:transfer`) and IP allowlist rule as
 * transfers.
 *
 * Flow: listRates() (optional) → createQuote() → createOrder() with the quote
 * id before the quote's expiresAt() (the bank sets the validity, typically
 * under two minutes). The order is SUBMITTED and queued; follow it with
 * getOrder(). There is no webhook for exchange orders.
 */
final class BankFxClient
{
    private ApiClient $api;

    public function __construct(ApiClient $api)
    {
        $this->api = $api;
    }

    /** @throws ApiException 412 `bank_connect.fx.capability_unavailable`. */
    public function listRates(string $connectionId): BankFxRates
    {
        return new BankFxRates(BankTransferRequests::dataOf(BankTransferRequests::send(
            $this->api,
            'listFxRates',
            [],
            ['connectionId' => BankTransferRequests::requireId($connectionId, 'connectionId')]
        )));
    }

    /**
     * Quotes an exchange (HTTP 201). Nothing is booked; one quote serves one order.
     *
     * @param array<string,mixed> $request sourceAccountId (you sell from it), targetAccountId (you buy
     *   into it) and exactly one of sourceAmountMinor / targetAmountMinor (integer > 0, minor units of
     *   that account's currency).
     * @throws ApiException 422 `bank_connect.fx.accounts_invalid`, `bank_connect.fx.pair_unsupported`,
     *   `bank_connect.fx.quote_rejected` (`details.reasonCode`).
     */
    public function createQuote(array $request): BankFxQuote
    {
        $body = BankTransferRequests::body('createFxQuote', $request);
        if (isset($body['sourceAmountMinor']) === isset($body['targetAmountMinor'])) {
            throw new ConfigurationException('createFxQuote takes exactly one of sourceAmountMinor and targetAmountMinor.');
        }
        if ($body['sourceAccountId'] === $body['targetAccountId']) {
            throw new ConfigurationException('createFxQuote needs two different accounts.');
        }

        return new BankFxQuote(BankTransferRequests::dataOf(BankTransferRequests::send($this->api, 'createFxQuote', [], [], $body)));
    }

    /**
     * Executes a quote (HTTP 201).
     *
     * @param array<string,mixed> $request quoteId, optional description (≤ 140 characters).
     * @param string|null $idempotencyKey Same rule as BankTransferClient::create(); generated when null.
     * @throws ApiException 409 `bank_connect.fx.quote_expired` / `bank_connect.fx.quote_used` (ask for a
     *   new quote), 409 `bank_connect.fx.idempotency_conflict`.
     */
    public function createOrder(array $request, ?string $idempotencyKey = null): BankFxOrderCreated
    {
        $body = BankTransferRequests::body('createFxOrder', $request);
        if (isset($body['description'])) {
            BankTransferRequests::description($body['description']);
        }
        $response = BankTransferRequests::send(
            $this->api,
            'createFxOrder',
            [],
            [],
            $body,
            BankTransferRequests::idempotencyKey($idempotencyKey)
        );

        return new BankFxOrderCreated(BankTransferRequests::dataOf($response));
    }

    /**
     * Exchange orders, newest first.
     *
     * @param array<string,mixed> $filters limit (1–100), cursor, status (one or a list of BankFxOrderStatus).
     * @return BankConnectPage<BankFxOrder>
     * @throws ApiException
     */
    public function listOrders(array $filters = []): BankConnectPage
    {
        return new BankConnectPage(
            BankTransferRequests::send($this->api, 'listFxOrders', [], BankTransferRequests::query('listFxOrders', $filters)),
            static fn (array $row): BankFxOrder => new BankFxOrder($row)
        );
    }

    /**
     * @param array<string,mixed> $filters See {@see listOrders()}.
     * @return Generator<int,BankFxOrder>
     */
    public function iterateOrders(array $filters = []): Generator
    {
        do {
            $page = $this->listOrders($filters);
            foreach ($page->items() as $item) {
                yield $item;
            }
            $filters['cursor'] = $page->nextCursor();
        } while ($page->hasMore());
    }

    /** @throws ApiException 404 `bank_connect.fx.order_not_found`. */
    public function getOrder(string $orderId): BankFxOrder
    {
        $response = BankTransferRequests::send(
            $this->api,
            'getFxOrder',
            ['orderId' => BankTransferRequests::requireId($orderId, 'orderId')]
        );

        return new BankFxOrder(BankTransferRequests::dataOf($response));
    }
}
