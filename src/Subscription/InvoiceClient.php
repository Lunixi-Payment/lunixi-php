<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Subscription;

use Lunixi\Sdk\ApiClient;

/**
 * Subscription invoices (billing history + dunning controls). Reads plus the
 * two admin operations: void an open invoice, and retry a failed charge.
 */
final class InvoiceClient
{
    private const BASE = '/api/v1/subscriptions/invoices';

    private ApiClient $api;

    public function __construct(ApiClient $api)
    {
        $this->api = $api;
    }

    /**
     * @param array{subscriptionId?:string, status?:string, pageSize?:int, pageToken?:string} $filters
     * @return CursorList<Invoice>
     */
    public function list(array $filters = []): CursorList
    {
        $response = $this->api->request('GET', self::BASE, null, ['query' => Helpers::query($filters)]);

        return new CursorList($response, static fn (array $row): Invoice => new Invoice($row));
    }

    public function get(string $invoiceId): Invoice
    {
        $response = $this->api->request('GET', self::BASE . '/' . rawurlencode($invoiceId));

        return new Invoice(Helpers::dataOf($response));
    }

    public function void(string $invoiceId, ?string $reason = null): Invoice
    {
        $body = ($reason !== null && $reason !== '') ? ['reason' => $reason] : null;
        $response = $this->api->request('POST', self::BASE . '/' . rawurlencode($invoiceId) . '/void', $body);

        return new Invoice(Helpers::dataOf($response));
    }

    public function retryCharge(string $invoiceId, ?string $idempotencyKey = null): Invoice
    {
        $response = $this->api->request('POST', self::BASE . '/' . rawurlencode($invoiceId) . '/retry-charge', null, [
            'idempotencyKey' => $idempotencyKey,
        ]);

        return new Invoice(Helpers::dataOf($response));
    }
}
