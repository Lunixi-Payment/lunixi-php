<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Subscription;

use Lunixi\Sdk\ApiClient;

/**
 * Saved payment cards for subscription auto-charge. Cards are added from a
 * checkout-SDK token; the SDK/back-end never sees the PAN.
 */
final class CardClient
{
    private const BASE = '/api/v1/subscriptions/cards';

    private ApiClient $api;

    public function __construct(ApiClient $api)
    {
        $this->api = $api;
    }

    public function add(AddCardRequest $request, ?string $idempotencyKey = null): Card
    {
        $response = $this->api->request('POST', self::BASE, $request->toArray(), [
            'idempotencyKey' => $idempotencyKey,
        ]);

        return new Card(Helpers::dataOf($response));
    }

    public function get(string $cardId): Card
    {
        $response = $this->api->request('GET', self::BASE . '/' . rawurlencode($cardId));

        return new Card(Helpers::dataOf($response));
    }

    /**
     * @return CursorList<Card>
     */
    public function list(string $customerId, bool $includeInactive = false, ?string $pageToken = null, ?int $pageSize = null): CursorList
    {
        $response = $this->api->request('GET', self::BASE, null, [
            'query' => Helpers::query([
                'customerId' => $customerId,
                'includeInactive' => $includeInactive,
                'pageToken' => $pageToken,
                'pageSize' => $pageSize,
            ]),
        ]);

        return new CursorList($response, static fn (array $row): Card => new Card($row));
    }

    public function setDefault(string $cardId): Card
    {
        $response = $this->api->request('POST', self::BASE . '/' . rawurlencode($cardId) . '/set-default');

        return new Card(Helpers::dataOf($response));
    }

    public function deactivate(string $cardId, ?string $reason = null): Card
    {
        $body = ($reason !== null && $reason !== '') ? ['reason' => $reason] : null;
        $response = $this->api->request('DELETE', self::BASE . '/' . rawurlencode($cardId), $body);

        return new Card(Helpers::dataOf($response));
    }

    /**
     * Verifies a saved card (e.g. a small auth/refund or 3-D check).
     *
     * @param array<string,mixed> $options
     */
    public function verify(string $cardId, array $options = []): Card
    {
        $response = $this->api->request('POST', self::BASE . '/' . rawurlencode($cardId) . '/verify', $options !== [] ? $options : null);

        return new Card(Helpers::dataOf($response));
    }

    /**
     * Updates card metadata (label, expiry, billing…). No PAN.
     *
     * @param array<string,mixed> $changes
     */
    public function update(string $cardId, array $changes): Card
    {
        $response = $this->api->request('PATCH', self::BASE . '/' . rawurlencode($cardId), $changes);

        return new Card(Helpers::dataOf($response));
    }
}
