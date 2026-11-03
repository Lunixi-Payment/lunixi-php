<?php

declare(strict_types=1);

namespace Lunixi\Sdk\PhysicalPos;

use Generator;
use Lunixi\Sdk\ApiClient;
use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Exception\LunixiException;
use Lunixi\Sdk\Exception\PhysicalPosCardDataRejectedException;
use Lunixi\Sdk\Exception\PhysicalPosOutcomeUnknownException;
use Lunixi\Sdk\Exception\PhysicalPosRateLimitException;
use Lunixi\Sdk\Exception\WebhookVerificationException;
use Lunixi\Sdk\Http\HttpResponse;
use Lunixi\Sdk\Webhook\WebhookVerifier;
use stdClass;

/**
 * Physical POS — card payments on your POS devices, with their reversals,
 * transactions, reports, end-of-day runs and results (`/api/v1/physical-pos`,
 * product PHYSICAL_POS).
 *
 *   createPayment                          POST /api/v1/physical-pos/payments                       (202, Idempotency-Key)
 *   createReversal                         POST …/payments/{paymentId}/reversals                    (202, Idempotency-Key)
 *   cancelPayment                          POST …/payments/{paymentId}/cancel                       (200)
 *   getPayment                             GET  …/payments/{paymentId}
 *   listPayments / iteratePayments         GET  …/payments
 *   listTransactions / iterateTransactions GET  …/transactions
 *   listAmbiguousPayments / iterate…       GET  …/transactions/ambiguous
 *   getTransaction                         GET  …/transactions/{paymentId}
 *   getSalesSummary                        GET  …/reports/sales-summary
 *   getTaxSummary                          GET  …/reports/tax-summary
 *   listSettlements / iterateSettlements   GET  …/settlements
 *   startSettlement                        POST …/devices/{deviceId}/settlement                     (202, Idempotency-Key)
 *   listSettlementRuns / iterate…          GET  …/settlement-runs
 *   getSettlementRun                       GET  …/settlement-runs/{runId}
 *
 * Every call is signed (step-up). Scopes: writes need `physical_pos:payments`;
 * reads accept `physical_pos:view` or `physical_pos:payments`. The key decides
 * the environment; a TEST key reads only and is refused on every write (403
 * `physical_pos.environment.test_key_read_only`). Resolving a MANUAL_REVIEW
 * payment is a panel-only action and has no API method.
 *
 * Starting a payment is asynchronous: HTTP 202 means the start command was
 * queued (`NOT_STARTED`), not that the card was charged. Follow the outcome by
 * webhook (parseWebhookEvent()) or getPayment().
 *
 * Idempotency: createPayment() and createReversal() REQUIRE an
 * `Idempotency-Key`. Create it with newIdempotencyKey(), store it with your
 * order, then call:
 *
 *     $key = PhysicalPosClient::newIdempotencyKey();
 *     $order->savePosKey($key);                       // persist first
 *     $result = $lunixi->physicalPos()->createPayment($request, $key);
 *
 * Every attempt of the call (the SDK repeats an uncertain one up to the
 * configured maxRetries) sends that same key and the same body. When the call
 * still ends without a definite answer you get a
 * PhysicalPosOutcomeUnknownException: call again with the SAME key — never a
 * new one, which can charge the card twice. For SALE, a `merchantReference`
 * with `allowDuplicateReference` false is a second, server-side net against a
 * double charge. cancelPayment() takes an optional key (one is generated).
 *
 * Card data is never accepted: the card is read on the device. A request or
 * filter key that looks like card data (`pan`, `cardNumber`, `cvv`, `expiry`,
 * `track2` …), or a card number inside `merchantReference`, an item name /
 * tax group or the `merchantReference` / `q` filters, is refused before
 * sending (PhysicalPosCardDataRejectedException, field path only); any other
 * unknown field is a ConfigurationException.
 */
final class PhysicalPosClient
{
    /** Fields of a payment target: exactly one of deviceId | vendorCode + serialNumber | externalRef (gateway rule). */
    public const TARGET_FIELDS = ['deviceId', 'vendorCode', 'serialNumber', 'externalRef'];

    /** Fields of a sale item (SALE only; Σ lineTotalMinor = amountMinor). */
    public const SALE_ITEM_FIELDS = [
        'lineNo', 'name', 'quantityMilli', 'unitCode', 'unitPriceMinor', 'taxGroupCode', 'lineTotalMinor', 'barcode',
    ];

    /** Fields of an item-based refund line. */
    public const REFUND_ITEM_FIELDS = ['lineNo', 'quantityMilli', 'amountMinor'];

    /** Fields of createPayment(). There is no card field. */
    public const PAYMENT_FIELDS = [
        'operation', 'target', 'amountMinor', 'currency', 'installmentCount', 'instrument', 'merchantReference',
        'allowDuplicateReference', 'startTimeoutSeconds', 'terminalAcquirerId', 'items', 'interaction',
        'terminalProviderId',
    ];

    /** Fields of createReversal(). */
    public const REVERSAL_FIELDS = [
        'operation', 'amountMinor', 'currency', 'merchantReference', 'startTimeoutSeconds', 'target', 'items',
    ];

    /** Query filters each read accepts. */
    public const FILTERS = [
        'listPayments' => ['limit', 'cursor', 'deviceId', 'paymentStatus', 'createdFrom', 'createdTo', 'merchantReference'],
        'listTransactions' => [
            'limit', 'cursor', 'deviceId', 'storeId', 'registerId', 'paymentStatus', 'createdFrom', 'createdTo',
            'merchantReference', 'amountFromMinor', 'amountToMinor', 'origin', 'operation',
        ],
        'listAmbiguousPayments' => ['limit', 'cursor', 'deviceId', 'paymentStatus'],
        'getSalesSummary' => ['dateFrom', 'dateTo', 'deviceId', 'storeId', 'groupBy'],
        'getTaxSummary' => ['dateFrom', 'dateTo', 'deviceId', 'storeId'],
        'listSettlements' => ['limit', 'cursor', 'deviceId', 'lineStatus', 'createdFrom', 'createdTo'],
        'listSettlementRuns' => [
            'limit', 'cursor', 'includeTotal', 'deviceId', 'source', 'runStatus', 'createdFrom', 'createdTo',
        ],
    ];

    private const BASE = '/api/v1/physical-pos';

    private ApiClient $api;

    public function __construct(ApiClient $api)
    {
        $this->api = $api;
    }

    /**
     * A new random Idempotency-Key (UUID v4) for one payment start or
     * reversal. Store it with your order BEFORE the call and reuse it to repeat
     * the call after an unknown outcome.
     */
    public static function newIdempotencyKey(): string
    {
        return PhysicalPosRequest::uuid4();
    }

    /**
     * Starts a payment on a device: `SALE`, `PREAUTH_OPEN` or
     * `REFUND_UNMATCHED`. New payment → 202 with `NOT_STARTED`; the same key
     * with the same body → 200, `replayed: true`, the current view.
     *
     * @param array<string,mixed> $request operation, target (`['deviceId' => …]` |
     *   `['vendorCode' => …, 'serialNumber' => …]` | `['externalRef' => …]`), amountMinor (canonical minor-unit string),
     *   currency, installmentCount?, instrument? (CARD by default; MEAL_CARD for a provider-collected meal card),
     *   merchantReference?, allowDuplicateReference?, startTimeoutSeconds?, terminalAcquirerId?, items? (SALE only;
     *   non-empty), interaction? (provider-collected cards only, required there: INSTRUMENT_READ — the device reads the
     *   card, CODE_DISPLAY — the device shows the provider's QR / short code; leave it out for CARD),
     *   terminalProviderId? (provider row id from PhysicalPosManagementClient::listTerminalProviders(); left out, the
     *   terminal's single active row for the method is used).
     * @param string|null $idempotencyKey REQUIRED (null is refused before sending): your stored key for this
     *   payment, sent as given (8–200 printable ASCII, no spaces; never trimmed). Repeat an unknown outcome with it.
     * @throws ConfigurationException
     * @throws PhysicalPosCardDataRejectedException
     * @throws PhysicalPosOutcomeUnknownException Retry with getIdempotencyKey().
     * @throws PhysicalPosRateLimitException
     * @throws ApiException Typed `physical_pos.*` codes (device_busy, device_offline, …).
     */
    public function createPayment(array $request, ?string $idempotencyKey = null): PhysicalPosPaymentWriteResult
    {
        $payload = PhysicalPosRequest::body($request, self::paymentSchema(), ['operation', 'target', 'amountMinor', 'currency'], 'createPayment request');
        PhysicalPosRequest::assertNoPanInPayment($payload);
        $key = PhysicalPosRequest::requireIdempotencyKey($idempotencyKey, 'a physical POS payment');

        return $this->paymentWrite('/payments', $payload, $key, 202);
    }

    /**
     * Reverses an approved root payment: `VOID`, `REFUND` (amount or item
     * based), `PREAUTH_CLOSE` or `PREAUTH_CANCEL`. Opens a child payment
     * (`originalPaymentId` = `rootPaymentId` = the root). 202 new / 200 replay.
     * Only a REFUND may run on another device (`target`).
     *
     * @param array<string,mixed> $request operation, currency, amountMinor? (REFUND / PREAUTH_CLOSE;
     *   leave it out for VOID / PREAUTH_CANCEL), merchantReference?, startTimeoutSeconds?, target? (REFUND only),
     *   items? (REFUND only: `[['lineNo' => 1, 'quantityMilli' => 1000]]`).
     * @param string|null $idempotencyKey REQUIRED, as for createPayment().
     * @throws ConfigurationException
     * @throws PhysicalPosCardDataRejectedException
     * @throws PhysicalPosOutcomeUnknownException
     * @throws PhysicalPosRateLimitException
     * @throws ApiException
     */
    public function createReversal(string $paymentId, array $request, ?string $idempotencyKey = null): PhysicalPosPaymentWriteResult
    {
        $path = '/payments/' . PhysicalPosRequest::pathId($paymentId, 'paymentId') . '/reversals';
        $payload = PhysicalPosRequest::body($request, self::reversalSchema(), ['operation', 'currency'], 'createReversal request');
        PhysicalPosRequest::assertNoPanInPayment($payload);
        $key = PhysicalPosRequest::requireIdempotencyKey($idempotencyKey, 'a physical POS reversal');

        return $this->paymentWrite($path, $payload, $key, 202);
    }

    /**
     * Cancels a payment whose start command has not reached the device. Always
     * 200: read paymentStatus() — only `EXPIRED` with `statusReason`
     * `MERCHANT_CANCELLED_BEFORE_DISPATCH` means it was cancelled; a payment
     * already final is returned as is (`replayed: true`, even when APPROVED —
     * use a void or refund for that). Repeating a cancel is safe.
     *
     * @param string|null $idempotencyKey Optional; null generates one (empty or blank keys are refused).
     * @throws PhysicalPosOutcomeUnknownException
     * @throws PhysicalPosRateLimitException
     * @throws ApiException 409 `physical_pos.payment.not_cancellable` (command dispatched, outcome unknown).
     */
    public function cancelPayment(string $paymentId, ?string $idempotencyKey = null): PhysicalPosPaymentWriteResult
    {
        $path = '/payments/' . PhysicalPosRequest::pathId($paymentId, 'paymentId') . '/cancel';
        $key = PhysicalPosRequest::idempotencyKey($idempotencyKey);

        return $this->paymentWrite($path, new stdClass(), $key, 200);
    }

    /**
     * One payment with its items and refund items. 404
     * `physical_pos.payment.not_found` for an unknown or another merchant's id.
     *
     * @throws ApiException
     */
    public function getPayment(string $paymentId): PhysicalPosPayment
    {
        $response = $this->read('/payments/' . PhysicalPosRequest::pathId($paymentId, 'paymentId'));
        $payment = PhysicalPosRequest::data($response)['payment'] ?? null;

        return new PhysicalPosPayment(is_array($payment) ? $payment : []);
    }

    /**
     * Payments and reversals, newest first (items not included; getPayment()
     * returns them). `merchantReference` finds a payment after an uncertain
     * network outcome.
     *
     * @param array<string,mixed> $filters limit (1–100), cursor, deviceId, paymentStatus, createdFrom, createdTo
     *   (ISO-8601 UTC with Z or DateTimeInterface; window ≤ 93 days, default the last 31 days), merchantReference.
     * @return PhysicalPosPage<PhysicalPosPayment>
     * @throws ApiException
     */
    public function listPayments(array $filters = []): PhysicalPosPage
    {
        return new PhysicalPosPage(
            $this->read('/payments', PhysicalPosRequest::query($filters, self::FILTERS['listPayments'], 'listPayments')),
            static fn (array $row): PhysicalPosPayment => new PhysicalPosPayment($row)
        );
    }

    /**
     * @param array<string,mixed> $filters Same as listPayments().
     * @return Generator<int,PhysicalPosPayment>
     */
    public function iteratePayments(array $filters = []): Generator
    {
        return PhysicalPosRequest::paginate(fn (array $page): PhysicalPosPage => $this->listPayments($page), $filters);
    }

    /**
     * Transactions as trees (root payment + its reversals), newest first.
     *
     * @param array<string,mixed> $filters limit, cursor, deviceId, storeId, registerId (assignment at payment time),
     *   paymentStatus (of the root), createdFrom, createdTo, merchantReference, amountFromMinor, amountToMinor (canonical minor-unit strings),
     *   origin (API | DEVICE_DIRECT), operation (SALE | PREAUTH_OPEN | REFUND_UNMATCHED).
     * @return PhysicalPosPage<PhysicalPosTransactionTree>
     * @throws ApiException
     */
    public function listTransactions(array $filters = []): PhysicalPosPage
    {
        return new PhysicalPosPage(
            $this->read('/transactions', PhysicalPosRequest::query($filters, self::FILTERS['listTransactions'], 'listTransactions')),
            static fn (array $row): PhysicalPosTransactionTree => new PhysicalPosTransactionTree($row)
        );
    }

    /**
     * @param array<string,mixed> $filters Same as listTransactions().
     * @return Generator<int,PhysicalPosTransactionTree>
     */
    public function iterateTransactions(array $filters = []): Generator
    {
        return PhysicalPosRequest::paginate(fn (array $page): PhysicalPosPage => $this->listTransactions($page), $filters);
    }

    /**
     * Payments with an unknown outcome or under manual review (`resolvable`
     * ones can be resolved in the panel).
     *
     * @param array<string,mixed> $filters limit, cursor, deviceId, paymentStatus (UNKNOWN | MANUAL_REVIEW).
     * @return PhysicalPosPage<PhysicalPosPayment>
     * @throws ApiException
     */
    public function listAmbiguousPayments(array $filters = []): PhysicalPosPage
    {
        return new PhysicalPosPage(
            $this->read('/transactions/ambiguous', PhysicalPosRequest::query($filters, self::FILTERS['listAmbiguousPayments'], 'listAmbiguousPayments')),
            static fn (array $row): PhysicalPosPayment => new PhysicalPosPayment($row)
        );
    }

    /**
     * @param array<string,mixed> $filters Same as listAmbiguousPayments().
     * @return Generator<int,PhysicalPosPayment>
     */
    public function iterateAmbiguousPayments(array $filters = []): Generator
    {
        return PhysicalPosRequest::paginate(fn (array $page): PhysicalPosPage => $this->listAmbiguousPayments($page), $filters);
    }

    /**
     * The tree of a root or child payment id with its timeline.
     *
     * @throws ApiException 404 `physical_pos.payment.not_found`.
     */
    public function getTransaction(string $paymentId): PhysicalPosTransactionDetail
    {
        $response = $this->read('/transactions/' . PhysicalPosRequest::pathId($paymentId, 'paymentId'));
        $transaction = PhysicalPosRequest::data($response)['transaction'] ?? null;

        return new PhysicalPosTransactionDetail(is_array($transaction) ? $transaction : []);
    }

    /**
     * @param array<string,mixed> $filters dateFrom, dateTo (YYYY-MM-DD business days, Europe/Istanbul, inclusive;
     *   default the last 7 days; ≤ 93 days; a DateTimeInterface is sent as its own calendar day, not converted),
     *   deviceId, storeId, groupBy (DAY | STORE | DEVICE | INSTRUMENT).
     * @throws ApiException
     */
    public function getSalesSummary(array $filters = []): PhysicalPosSalesSummary
    {
        $response = $this->read('/reports/sales-summary', PhysicalPosRequest::query($filters, self::FILTERS['getSalesSummary'], 'getSalesSummary'));

        return new PhysicalPosSalesSummary(PhysicalPosRequest::data($response));
    }

    /**
     * @param array<string,mixed> $filters dateFrom, dateTo, deviceId, storeId.
     * @throws ApiException
     */
    public function getTaxSummary(array $filters = []): PhysicalPosTaxSummary
    {
        $response = $this->read('/reports/tax-summary', PhysicalPosRequest::query($filters, self::FILTERS['getTaxSummary'], 'getTaxSummary'));

        return new PhysicalPosTaxSummary(PhysicalPosRequest::data($response));
    }

    /**
     * End-of-day results, one row per bank line.
     *
     * @param array<string,mixed> $filters limit, cursor (opaque), deviceId, lineStatus (MATCHED | MISMATCH | UNMAPPED),
     *   createdFrom, createdTo (end-of-day command time).
     * @return PhysicalPosPage<PhysicalPosSettlementLine>
     * @throws ApiException
     */
    public function listSettlements(array $filters = []): PhysicalPosPage
    {
        return new PhysicalPosPage(
            $this->read('/settlements', PhysicalPosRequest::query($filters, self::FILTERS['listSettlements'], 'listSettlements')),
            static fn (array $row): PhysicalPosSettlementLine => new PhysicalPosSettlementLine($row)
        );
    }

    /**
     * @param array<string,mixed> $filters Same as listSettlements().
     * @return Generator<int,PhysicalPosSettlementLine>
     */
    public function iterateSettlements(array $filters = []): Generator
    {
        return PhysicalPosRequest::paginate(fn (array $page): PhysicalPosPage => $this->listSettlements($page), $filters);
    }

    /**
     * Starts end-of-day (batch close) on a device (`physical_pos:payments`).
     * End-of-day cannot be undone. The answer means "accepted": the device
     * reports the result later — follow it with getSettlementRun() or
     * listSettlements(). New run → 202; the same key → 200, `replayed: true`.
     * A TEST key is refused with 403 `physical_pos.environment.test_key_read_only`.
     *
     * @param string|null $idempotencyKey Sent as given; null generates one (returned by idempotencyKey()).
     *   After an unknown outcome call again with the same key.
     * @return PhysicalPosWriteResult record() is the `run`.
     * @throws ConfigurationException
     * @throws PhysicalPosRateLimitException
     * @throws ApiException
     */
    public function startSettlement(string $deviceId, ?string $idempotencyKey = null): PhysicalPosWriteResult
    {
        $path = '/devices/' . PhysicalPosRequest::pathId($deviceId, 'deviceId') . '/settlement';
        $key = PhysicalPosRequest::idempotencyKey($idempotencyKey);
        try {
            $response = $this->api->request('POST', self::BASE . $path, new stdClass(), ['stepUp' => true, 'idempotencyKey' => $key]);
        } catch (ApiException $error) {
            throw PhysicalPosRequest::mapError($error);
        }
        $data = PhysicalPosRequest::data($response);
        $replayed = ($data['replayed'] ?? false) === true;

        return new PhysicalPosWriteResult($data, 'run', PhysicalPosRequest::statusCode($response, $replayed ? 200 : 202), $key);
    }

    /**
     * End-of-day runs started by Lunixi (manual and scheduled), newest first.
     * Each run: runId, deviceId, environment, source, runStatus, reason,
     * businessDate, commandId, commandStatus, deliveryPath, resultState,
     * attemptCount, nextAttemptAt, windowEndsAt, resultLineCount, completedAt,
     * createdAt, updatedAt.
     *
     * @param array<string,mixed> $filters limit, cursor, includeTotal, deviceId, source (MANUAL_API | MANUAL_PANEL |
     *   SCHEDULED), runStatus (PENDING | STARTED | SKIPPED | FAILED), createdFrom, createdTo (run creation time).
     * @return PhysicalPosPage<array<string,mixed>>
     * @throws ApiException
     */
    public function listSettlementRuns(array $filters = []): PhysicalPosPage
    {
        return new PhysicalPosPage(
            $this->read('/settlement-runs', PhysicalPosRequest::query($filters, self::FILTERS['listSettlementRuns'], 'listSettlementRuns'))
        );
    }

    /**
     * @param array<string,mixed> $filters Same as listSettlementRuns().
     * @return Generator<int,array<string,mixed>>
     */
    public function iterateSettlementRuns(array $filters = []): Generator
    {
        return PhysicalPosRequest::paginate(fn (array $page): PhysicalPosPage => $this->listSettlementRuns($page), $filters);
    }

    /**
     * One end-of-day run with its command status and result state.
     *
     * @return array<string,mixed> The run.
     * @throws ApiException
     */
    public function getSettlementRun(string $runId): array
    {
        $run = PhysicalPosRequest::data($this->read('/settlement-runs/' . PhysicalPosRequest::pathId($runId, 'runId')))['run'] ?? null;

        return is_array($run) ? $run : [];
    }

    /**
     * Verifies a delivered `physical_pos.*` webhook with WebhookVerifier and
     * reads it. There is no way to read an unverified body: the raw body,
     * the request headers and your endpoint secret are required, and any
     * signature, timestamp or header failure throws.
     *
     * The payload is the envelope `data`, or its `payloadJson` (object or JSON
     * text).
     *
     * @param string               $rawBody   The exact raw request body.
     * @param array<string,string> $headers   The request headers (any case).
     * @param string               $secret    The endpoint's webhook secret.
     * @param int|null             $tolerance Timestamp window in seconds (default 300; null disables it).
     * @throws WebhookVerificationException Signature, timestamp or header check failed.
     * @throws ConfigurationException Verified, but not a physical POS event or without a payload.
     */
    public static function parseWebhookEvent(
        string $rawBody,
        array $headers,
        string $secret,
        ?int $tolerance = WebhookVerifier::DEFAULT_TOLERANCE_SECONDS
    ): PhysicalPosWebhookEvent {
        return PhysicalPosWebhookEvent::verify($rawBody, $headers, $secret, $tolerance);
    }

    /**
     * One payment write with its own attempt loop (the transport's retries are
     * off for it). Every attempt sends the same key and the same body.
     *
     *  - success: a 2xx whose JSON has `data.payment` with a `paymentId`;
     *  - uncertain: no HTTP answer, a 2xx without a readable payment, or a 5xx
     *    other than {@see PhysicalPosRequest::NOT_STARTED_CODES} — repeated;
     *  - not started: a typed "nothing was written" 5xx — repeated, not uncertain;
     *  - definite: any 4xx — stops.
     * Once an attempt was uncertain the call ends with
     * PhysicalPosOutcomeUnknownException whatever the last answer is.
     *
     * @param array<string,mixed>|stdClass $payload
     */
    private function paymentWrite(string $path, $payload, string $key, int $newStatus): PhysicalPosPaymentWriteResult
    {
        $attempts = max(1, $this->api->maxRetries() + 1);
        $uncertain = false;
        $last = null;
        $attempt = 1;
        for (; $attempt <= $attempts; $attempt++) {
            try {
                $response = $this->api->requestRaw('POST', self::BASE . $path, $payload, [
                    'stepUp' => true,
                    'idempotencyKey' => $key,
                    'retry' => false,
                ]);
                $result = self::writeResult($response, $key, $newStatus);
                if ($result !== null) {
                    return $result;
                }
                $last = new ApiException(
                    'The gateway answered the payment request without a readable payment.',
                    0,
                    null,
                    null,
                    $response->header('x-request-id'),
                    null,
                    $response->headers()
                );
            } catch (LunixiException $error) {
                if (!$error instanceof ApiException) {
                    // Refused locally before sending (configuration, signing, token): not a gateway answer.
                    if ($uncertain) {
                        throw new PhysicalPosOutcomeUnknownException($error, $key, $attempt);
                    }
                    throw $error;
                }
                $last = $error;
            } catch (\Throwable $error) {
                $last = $error;
            }
            if (PhysicalPosRequest::isUncertain($last)) {
                $uncertain = true;
            }
            if ($attempt < $attempts && PhysicalPosRequest::isRetryable($last)) {
                self::backoff($attempt);
                continue;
            }
            break;
        }

        if ($uncertain) {
            throw new PhysicalPosOutcomeUnknownException($last, $key, min($attempt, $attempts));
        }
        if ($last instanceof ApiException) {
            throw PhysicalPosRequest::mapError($last);
        }
        throw $last;
    }

    /** A write result, or null when the 2xx carries no readable payment. */
    private static function writeResult(HttpResponse $response, string $key, int $newStatus): ?PhysicalPosPaymentWriteResult
    {
        if (!$response->isSuccess() || trim($response->body()) === '') {
            return null;
        }
        $decoded = json_decode($response->body(), true);
        if (!is_array($decoded)) {
            return null;
        }
        $data = $decoded['data'] ?? null;
        $payment = is_array($data) ? ($data['payment'] ?? null) : null;
        if (!is_array($payment) || !is_string($payment['paymentId'] ?? null) || $payment['paymentId'] === '') {
            return null;
        }
        try { PhysicalPosMoney::validate($decoded); } catch (ConfigurationException $error) { return null; }
        $replayed = ($data['replayed'] ?? false) === true;

        return new PhysicalPosPaymentWriteResult(
            new PhysicalPosPayment($payment),
            $replayed,
            PhysicalPosRequest::statusCode($decoded, $replayed ? 200 : $newStatus),
            $key
        );
    }

    private static function backoff(int $attempt): void
    {
        // Same schedule as the transport: 100 ms, 200 ms, 400 ms … capped at 2 s.
        usleep(min(2000, 100 * (2 ** ($attempt - 1))) * 1000);
    }

    /**
     * @param array<string,string|int> $query
     * @return array<string,mixed>
     */
    private function read(string $path, array $query = []): array
    {
        $options = ['stepUp' => true];
        if ($query !== []) {
            $options['query'] = $query;
        }
        try {
            $response = $this->api->request('GET', self::BASE . $path, null, $options);
            PhysicalPosMoney::validate($response);
            return $response;
        } catch (ApiException $error) {
            throw PhysicalPosRequest::mapError($error);
        }
    }

    /** @return array<string,array{object:array{fields:array<string,string>}}> */
    private static function target(bool $nullable): array
    {
        return [($nullable ? '?' : '') . 'object' => ['fields' => array_fill_keys(self::TARGET_FIELDS, 'string')]];
    }

    /** @return array<string,mixed> */
    private static function paymentSchema(): array
    {
        return [
            'operation' => 'string',
            'target' => self::target(false),
            'amountMinor' => 'money',
            'currency' => 'string',
            'installmentCount' => '?int',
            'instrument' => '?string',
            'merchantReference' => '?string',
            'allowDuplicateReference' => 'bool',
            'startTimeoutSeconds' => '?int',
            'terminalAcquirerId' => '?string',
            'interaction' => '?string',
            'terminalProviderId' => '?string',
            'items' => ['list' => [
                'fields' => [
                    'lineNo' => 'int',
                    'name' => 'string',
                    'quantityMilli' => 'int',
                    'unitCode' => 'string',
                    'unitPriceMinor' => 'money',
                    'taxGroupCode' => 'string',
                    'lineTotalMinor' => 'money',
                    'barcode' => '?string',
                ],
                'required' => ['lineNo', 'name', 'quantityMilli', 'unitCode', 'unitPriceMinor', 'taxGroupCode', 'lineTotalMinor'],
            ]],
        ];
    }

    /** @return array<string,mixed> */
    private static function reversalSchema(): array
    {
        return [
            'operation' => 'string',
            'amountMinor' => 'money',
            'currency' => 'string',
            'merchantReference' => '?string',
            'startTimeoutSeconds' => '?int',
            'target' => self::target(true),
            'items' => ['list' => [
                'fields' => ['lineNo' => 'int', 'quantityMilli' => 'int', 'amountMinor' => 'money'],
                'required' => ['lineNo', 'quantityMilli'],
            ]],
        ];
    }
}
