<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Fraud;

use Lunixi\Sdk\ApiClient;
use Lunixi\Sdk\Exception\ConfigurationException;

/**
 * Transaction event ingest — the history that counting rules read.
 *
 *   record       POST /api/v1/fraud/events/transactions
 *   recordBatch  POST /api/v1/fraud/events/transactions/batch
 *
 * 🔴 THESE TWO ROUTES ARE SIGNED PER REQUEST, not bearer-only like the decision
 *    surface. `stepUp: true` makes {@see ApiClient} attach the Ed25519 signature
 *    headers (`X-Key-Id` / `X-Date` / `X-Nonce` / `Digest` / `X-Signature`); the
 *    signature is what binds the event to the account, so a body value cannot
 *    claim a different merchant. `merchantId` is stamped from the authenticated
 *    key and is never read from the body.
 *
 * ⚠️ `/api/v1` PREFIX IS PART OF THE PATH. The gateway applies a global prefix and
 *    fraud is not in its exclude list; a path written without the prefix returns
 *    404 for the whole surface (measured on the decision client before 0.11.0).
 */
final class FraudEventsClient
{
    private const BASE = '/api/v1/fraud';

    /**
     * Hard upper bound the gateway enforces per batch call. Checked locally as
     * well, because the gateway rejects the WHOLE batch above it — failing before
     * the network keeps the caller from losing 500 events to one oversized call.
     */
    public const BATCH_LIMIT = 500;

    private ApiClient $api;

    public function __construct(ApiClient $api)
    {
        $this->api = $api;
    }

    /**
     * Records a single business event.
     *
     * Required keys: `eventId`, `eventType`, `subjectType`, `occurredAt`.
     * `eventType` must be a value the gateway's vocabulary knows — an unknown
     * type is a 400 rather than a silently unused row.
     *
     * Retrying with the same `eventId` is safe: the second call is deduplicated
     * and reports `deduplicated() === true`.
     *
     * @param array<string,mixed> $event
     */
    public function record(array $event, ?string $idempotencyKey = null): FraudEventResult
    {
        if ($event === []) {
            throw new ConfigurationException('Fraud event must not be empty.');
        }

        $response = $this->api->request(
            'POST',
            self::BASE . '/events/transactions',
            $event,
            ['stepUp' => true, 'idempotencyKey' => $idempotencyKey]
        );

        return new FraudEventResult(self::dataOf($response));
    }

    /**
     * Records up to {@see BATCH_LIMIT} events in one signed call.
     *
     * The body is sent as a JSON array. The batch is processed serially and CAN
     * SUCCEED PARTIALLY — read {@see FraudEventBatchResult::failures()}.
     *
     * @param list<array<string,mixed>> $events
     */
    public function recordBatch(array $events, ?string $idempotencyKey = null): FraudEventBatchResult
    {
        if ($events === []) {
            throw new ConfigurationException('Fraud event batch must not be empty.');
        }
        if (!self::isList($events)) {
            throw new ConfigurationException('Fraud event batch must be a list of events.');
        }
        if (count($events) > self::BATCH_LIMIT) {
            throw new ConfigurationException(sprintf(
                'Fraud event batch holds %d events; the per-request limit is %d. Split the feed into chunks.',
                count($events),
                self::BATCH_LIMIT
            ));
        }

        $response = $this->api->request(
            'POST',
            self::BASE . '/events/transactions/batch',
            $events,
            ['stepUp' => true, 'idempotencyKey' => $idempotencyKey]
        );

        return new FraudEventBatchResult(self::dataOf($response));
    }

    /**
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    private static function dataOf(array $response): array
    {
        return isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
    }

    /**
     * `array_is_list()` equivalent — the built-in needs PHP 8.1 and this package
     * supports 7.4 (`composer.json` `"php": ">=7.4"`).
     *
     * @param array<mixed> $value
     */
    private static function isList(array $value): bool
    {
        $expected = 0;
        foreach ($value as $key => $_) {
            if ($key !== $expected++) {
                return false;
            }
        }

        return true;
    }
}
