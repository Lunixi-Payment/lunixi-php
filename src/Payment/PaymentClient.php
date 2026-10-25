<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

use Lunixi\Sdk\ApiClient;
use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Http\Envelope;

/**
 * Server-side payment operations against the gateway. The browser form/3DS flow
 * is handled by the embedded JS SDK using the token from createIntent(); this
 * client covers the operations a merchant backend performs:
 *
 *   createIntent  POST /api/v1/payments/intents       (step-up)         → token + paymentId
 *   capture       POST /api/v1/payments/{id}/capture  (step-up, Idem-Key)
 *   refund        POST /api/v1/payments/{id}/refund   (step-up, Idem-Key)
 *   void          POST /api/v1/payments/{id}/void     (step-up, Idem-Key)
 *   get           GET  /api/v1/payments/{id}          (bearer)
 *   list          GET  /api/v1/payments               (bearer)
 *
 * Payment methods (Faturana Yansıt, wallets, card) from the merchant server:
 *
 *   directMethodPayment  POST /api/v1/payments/direct/payment                      (step-up, Idem-Key)
 *   confirmPaymentAction POST /api/v1/payments/{id}/actions/{actionId}/confirm     (step-up)
 *   resendPaymentAction  POST /api/v1/payments/{id}/actions/{actionId}/resend      (step-up)
 *   listPaymentMethods   GET  /api/v1/payments/methods[?paymentMethod=]            (step-up)
 *
 * Senetle Ödeme (promissory notes, `paymentMethod: PROMISSORY_NOTE`):
 *
 *   getPromissoryNotes             GET  /api/v1/payments/{id}/promissory-notes           (step-up)
 *   cancelPromissoryNotes          POST /api/v1/payments/{id}/promissory-notes/cancel    (step-up)
 *   downloadPromissoryNoteDocument GET  /api/v1/payments/{id}/promissory-notes/document  (step-up, PDF)
 *
 * Split payments (one payment funded by stored cards and non-card methods):
 *
 *   createSplitPayment  POST /api/v1/payments/split                      (step-up, Idem-Key)
 *   getSplitPayment     GET  /api/v1/payments/split/{id}                 (bearer)
 *   listSplitPayments   GET  /api/v1/payments/split                      (bearer)
 *   refundSplitPayment  POST /api/v1/payments/split/{id}/refund          (step-up, Idem-Key)
 *
 * A card is paid from the server with directMethodPayment() and
 * DirectMethodPaymentRequest::withCard() (withForce3D(true) for 3D Secure);
 * the former direct 2D/3D card endpoints were removed.
 *
 * capture/refund/void/direct-payment/card-storage mutations REQUIRE a stable
 * Idempotency-Key from the caller so cross-process retries are de-duplicated
 * by the gateway instead of creating a fresh financial operation.
 */
final class PaymentClient
{
    private const BASE = '/api/v1/payments';

    private const SERIAL_NUMBER_PATTERN = '/^[A-Za-z0-9-]{4,64}$/';

    /** Longest Idempotency-Key the gateway accepts on the split endpoints. */
    private const SPLIT_IDEMPOTENCY_KEY_MAX_LENGTH = 200;

    /** Filters of GET /payments/split (gateway ListSplitPaymentsDto). */
    public const LIST_SPLIT_PAYMENTS_FILTERS = ['status', 'currency', 'createdFrom', 'createdTo', 'orderId', 'ownerRef', 'limit', 'after'];

    private const SPLIT_REFUND_REASON_MAX_LENGTH = 500;

    /**
     * Amount fields removed from the payment bodies: `amount` is the only
     * amount field of the intent, marketplace and direct/payment bodies (gateway answers these with 400 `payment.amount.field_removed`).
     */
    public const REMOVED_AMOUNT_FIELDS = ['price', 'paidPrice'];

    private ApiClient $api;

    public function __construct(ApiClient $api)
    {
        $this->api = $api;
    }

    /**
     * Creates a checkout intent. Returns the browser token + paymentId.
     *
     * @throws ApiException on an init failure (non-2xx, or success=false/no token).
     */
    public function createIntent(CreateIntentRequest $request, ?string $idempotencyKey = null): CheckoutIntent
    {
        $response = $this->api->request('POST', self::BASE . '/intents', $request->toArray(), [
            'stepUp' => true,
            'idempotencyKey' => $idempotencyKey, // optional for create; gateway derives one if absent
        ]);

        $intent = new CheckoutIntent($response);
        if ($intent->token() === '') {
            throw new ApiException(
                $intent->message() !== '' ? $intent->message() : 'Checkout intent creation did not return a token.',
                0,
                $intent->code() !== '' ? $intent->code() : null,
                $response
            );
        }

        return $intent;
    }

    /**
     * Creates a marketplace hosted-checkout intent. Payload follows
     * MarketplaceCreatePaymentIntentDto (amount/currency/orderId plus sellers).
     * The sellers' `amountMinor` values must add up to `amount`; the gateway
     * refuses any other total with 400. `price` / `paidPrice` are refused before
     * sending (see REMOVED_AMOUNT_FIELDS).
     *
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public function createMarketplaceIntent(array $request, ?string $idempotencyKey = null): array
    {
        self::assertNoRemovedAmountFields($request);

        return $this->api->request('POST', self::BASE . '/marketplace/intents', $request, [
            'stepUp' => true,
            'idempotencyKey' => $idempotencyKey,
        ]);
    }

    /** Captures an authorized payment. Omit $amount for a full capture (else minor units). */
    public function capture(string $intentId, ?int $amount = null, ?string $idempotencyKey = null): PaymentIntent
    {
        $body = ['paymentIntentId' => $intentId];
        if ($amount !== null) {
            $body['amount'] = $amount;
        }

        return $this->mutate($intentId, 'capture', $body, $idempotencyKey);
    }

    /** Refunds a captured payment. Omit $amount for a full refund (else minor units). */
    public function refund(string $intentId, ?int $amount = null, ?string $reason = null, ?string $idempotencyKey = null): PaymentIntent
    {
        $body = ['paymentIntentId' => $intentId];
        if ($amount !== null) {
            $body['amount'] = $amount;
        }
        if ($reason !== null && $reason !== '') {
            $body['reason'] = $reason;
        }

        return $this->mutate($intentId, 'refund', $body, $idempotencyKey);
    }

    /** Voids an authorized-but-uncaptured payment. */
    public function void(string $intentId, ?string $idempotencyKey = null): PaymentIntent
    {
        return $this->mutate($intentId, 'void', null, $idempotencyKey);
    }

    /**
     * Starts a payment with any payment method from the merchant server
     * (POST /payments/direct/payment). `$idempotencyKey` is required: reuse the
     * same key when retrying the same payment.
     *
     * While the buyer must act (HTTP 202) the result carries action(): an OTP
     * is confirmed with confirmPaymentAction(), a REDIRECT sends the buyer to
     * `redirectUrl()`. A declined payment is HTTP 400 and throws ApiException;
     * `new MethodPaymentResult($e->getResponseBody() ?? [])` reads its fields.
     *
     * On a method whose `providerSelectionMode` is PLATFORM, a request without
     * `providerCode` lets the platform pick the provider; when none of the
     * connected providers can take the payment the gateway answers 422
     * `payment.provider.none_eligible` (`details.candidates[]`) and nothing is
     * charged.
     *
     * @throws ApiException
     */
    public function directMethodPayment(DirectMethodPaymentRequest $request, ?string $idempotencyKey = null): MethodPaymentResult
    {
        $body = $request->toArray();
        $key = self::requireIdempotencyKey('direct method payment', $idempotencyKey);

        return new MethodPaymentResult($this->api->request('POST', self::BASE . '/direct/payment', $body, [
            'stepUp' => true,
            'idempotencyKey' => $key,
        ]));
    }

    /**
     * Completes the open action of a payment: the buyer's code, the agreement
     * consent or the values an INPUT action asks for. It does not start a new
     * charge; repeating the same confirm returns the recorded result. A
     * rejected code is HTTP 422 (ApiException; `details.attemptsRemaining` in
     * the body) and the action stays open.
     *
     * @throws ApiException
     */
    public function confirmPaymentAction(string $paymentId, string $actionId, PaymentActionConfirmRequest $request): MethodPaymentResult
    {
        return new MethodPaymentResult($this->api->request(
            'POST',
            self::actionPath($paymentId, $actionId, 'confirm'),
            $request->toArray(),
            ['stepUp' => true]
        ));
    }

    /**
     * Sends a new one-time code. The previous action is closed; confirm with
     * the new action()->id().
     *
     * @throws ApiException
     */
    public function resendPaymentAction(string $paymentId, string $actionId): MethodPaymentResult
    {
        return new MethodPaymentResult($this->api->request(
            'POST',
            self::actionPath($paymentId, $actionId, 'resend'),
            null,
            ['stepUp' => true]
        ));
    }

    /**
     * The payment methods open for this account: block name, provider
     * selection (`providerSelection`, `providerSelectionMode`), preauth support,
     * limits, split payment eligibility (splitEligible(), splitBehavior()) and
     * each provider's `inputFields`.
     *
     * Methods and providers come in the order set in the form settings (the
     * platform's default order when none is set). A provider turned off only
     * in the checkout form stays in this list and can be used through the API.
     *
     * `$paymentMethod` lists that method only (`?paymentMethod=`, `QR` is sent
     * as `QR_CODE`); a method that is not open for the account gives an empty
     * list. A code that is not upper case throws ConfigurationException before
     * sending; an unknown code is a 400 `payment.method.unknown`.
     */
    public function listPaymentMethods(?string $paymentMethod = null): PaymentMethodList
    {
        $options = ['stepUp' => true];
        if ($paymentMethod !== null) {
            $options['query'] = ['paymentMethod' => PaymentMethod::normalize($paymentMethod)];
        }

        return new PaymentMethodList($this->api->request('GET', self::BASE . '/methods', null, $options));
    }

    /**
     * The notes of a Senetle Ödeme payment (signed): serial number, sequence, amount,
     * due date and status (PromissoryNoteStatus) of each. Statuses are
     * refreshed from the provider periodically; changes also arrive as
     * `payment.promissory_note.*` webhooks. A payment without notes (or of
     * another merchant) is HTTP 404 `payment.promissory_note.not_found`.
     *
     * @throws ApiException
     */
    public function getPromissoryNotes(string $paymentId): PromissoryNotes
    {
        return new PromissoryNotes($this->api->request('GET', self::promissoryNotesPath($paymentId), null, ['stepUp' => true]));
    }

    /**
     * Cancels notes at the provider (signed). Without `$serialNumbers` every
     * open note is cancelled. The answer is the note list plus
     * cancelResults() per note (PromissoryNoteCancelResult; a collected note is
     * NOT_CANCELLABLE) with a Lunixi `errorCode` (ERROR_PROVIDER_UNAVAILABLE is
     * temporary, ERROR_CANCEL_REJECTED permanent; never the provider's code).
     * The payment stays CAPTURED: no funds moved through the
     * platform, so there is nothing to refund.
     *
     * @param list<string>|null $serialNumbers 1–24 serial numbers from getPromissoryNotes().
     * @throws ApiException
     */
    public function cancelPromissoryNotes(string $paymentId, ?array $serialNumbers = null, ?string $idempotencyKey = null): PromissoryNotes
    {
        $body = null;
        if ($serialNumbers !== null) {
            if ($serialNumbers === [] || count($serialNumbers) > PromissoryNotePlan::MAX_ITEMS) {
                throw new ConfigurationException(sprintf(
                    'serialNumbers must list 1 to %d note serial numbers; pass null to cancel every open note.',
                    PromissoryNotePlan::MAX_ITEMS
                ));
            }
            foreach ($serialNumbers as $serialNumber) {
                if (!is_string($serialNumber) || preg_match(self::SERIAL_NUMBER_PATTERN, $serialNumber) !== 1) {
                    throw new ConfigurationException('serialNumbers contains an invalid serial number (letters, digits and -, 4 to 64 characters).');
                }
            }
            $body = ['serialNumbers' => array_values($serialNumbers)];
        }
        $options = ['stepUp' => true];
        if ($idempotencyKey !== null && trim($idempotencyKey) !== '') {
            $options['idempotencyKey'] = trim($idempotencyKey);
        }

        return new PromissoryNotes($this->api->request('POST', self::promissoryNotesPath($paymentId) . '/cancel', $body, $options));
    }

    /**
     * The printable notes as a PDF, one note per page (signed). Built from the
     * provider's current data and not stored: download it when you need it.
     * `$locale` is PromissoryNoteDocument::LOCALE_TR (default) or LOCALE_EN.
     * HTTP 503 `payment.promissory_note.document_unavailable` means the
     * provider could not be reached; try again later.
     *
     * @throws ApiException
     */
    public function downloadPromissoryNoteDocument(string $paymentId, ?string $locale = null): PromissoryNoteDocument
    {
        $options = ['stepUp' => true, 'accept' => 'application/pdf, application/json'];
        if ($locale !== null) {
            if (!in_array($locale, PromissoryNoteDocument::LOCALES, true)) {
                throw new ConfigurationException(sprintf('locale must be one of %s.', implode(', ', PromissoryNoteDocument::LOCALES)));
            }
            $options['query'] = ['locale' => $locale];
        }
        $response = $this->api->requestBinary('GET', self::promissoryNotesPath($paymentId) . '/document', $options);

        return new PromissoryNoteDocument((string) $response->header('content-type'), $response->body());
    }

    /** @return array<string,mixed> */
    public function binInfo(string $binOrPan): array
    {
        return $this->api->request('POST', '/api/v1/bin/info', ['binOrPan' => $binOrPan]);
    }

    /** @return array<string,mixed> */
    public function installmentOptions(InstallmentOptionsRequest $request): array
    {
        return $this->api->request('POST', '/api/v1/bin/installments', $request->toArray());
    }

    /** @return array<string,mixed> */
    public function cardTaxonomy(): array
    {
        return $this->api->request('GET', '/api/v1/bin/card-taxonomy');
    }

    /** @return array<string,mixed> */
    public function storeCard(StoreCardRequest $request, ?string $idempotencyKey = null): array
    {
        return $this->api->request('POST', self::BASE . '/cards', $request->toArray(), [
            'stepUp' => true,
            'idempotencyKey' => self::requireIdempotencyKey('store card', $idempotencyKey),
        ]);
    }

    /** @return array<string,mixed> */
    public function listStoredCards(string $cardUserKey): array
    {
        return $this->api->request('GET', self::BASE . '/cards', null, ['query' => ['cardUserKey' => $cardUserKey]]);
    }

    /** @return array<string,mixed> */
    public function getStoredCard(string $storedCardToken): array
    {
        return $this->api->request('GET', self::BASE . '/cards/' . rawurlencode($storedCardToken));
    }

    /** @return array<string,mixed> */
    public function deactivateStoredCard(string $storedCardToken, ?string $idempotencyKey = null): array
    {
        return $this->api->request('DELETE', self::BASE . '/cards/' . rawurlencode($storedCardToken), null, [
            'stepUp' => true,
            'idempotencyKey' => self::requireIdempotencyKey('deactivate stored card', $idempotencyKey),
        ]);
    }

    /**
     * Failover-recovery analytics — volume/count of payments rescued by provider
     * failover retries (merchant reporting).
     *
     * @param array<string,mixed> $filters from/to/range…
     * @return array<string,mixed>
     */
    public function failoverRecoveryAnalytics(array $filters = []): array
    {
        return $this->api->request('GET', self::BASE . '/analytics/failover-recovery', null, ['query' => $filters]);
    }

    /**
     * Per-credential provider performance analytics.
     *
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function providerCredentialAnalytics(string $credentialId, array $filters = []): array
    {
        return $this->api->request('GET', self::BASE . '/analytics/provider-credentials/' . rawurlencode($credentialId), null, ['query' => $filters]);
    }

    /** Fetches a payment intent by id (reconciliation / status). */
    public function get(string $intentId): PaymentIntent
    {
        $response = $this->api->request('GET', self::BASE . '/' . rawurlencode($intentId));

        return new PaymentIntent(self::dataOf($response));
    }

    /**
     * Lists payment intents. A split payment is one row (its main payment,
     * PaymentIntent::split() ROLE_PARENT); `includeLegs => true` also returns
     * each leg as a row (ROLE_LEG).
     *
     * @param array{page?:int, limit?:int, status?:string, customerId?:string, includeLegs?:bool} $filters
     */
    public function list(array $filters = []): PaymentList
    {
        $query = [];
        foreach (['page', 'limit', 'status', 'customerId'] as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $query[$key] = $filters[$key];
            }
        }
        if (array_key_exists('includeLegs', $filters) && $filters['includeLegs'] !== null) {
            if (!is_bool($filters['includeLegs'])) {
                throw new ConfigurationException('includeLegs must be a boolean.');
            }
            $query['includeLegs'] = $filters['includeLegs'] ? 'true' : 'false';
        }

        $response = $this->api->request('GET', self::BASE, null, ['query' => $query]);

        return new PaymentList($response);
    }

    /**
     * Starts a split payment, or continues it (POST /payments/split). The
     * Idempotency-Key is required and is the identity of the split payment:
     * after a step (an OTP confirmed, the buyer back from 3D Secure) send the
     * SAME request with the SAME key. No new payment is created; the payment
     * continues where it stopped and the next non-card leg starts with its
     * block from this request.
     *
     * pendingStep() tells what the payment waits for:
     *   - THREE_DS: send the buyer to redirectPath();
     *   - ACTION: confirmPaymentAction(paymentId(), actionId(), …), then call
     *     this method again;
     *   - START_METHOD: call this method again;
     *   - RESULT_PENDING, CREDIT_APPROVAL: read it later with getSplitPayment();
     *     the result also arrives by webhook.
     *
     * While the payment is running (SplitPaymentStatus::IN_PROGRESS) the
     * gateway answers with HTTP 400 and the status as `code`; that answer is
     * returned here, not thrown. COMPLETED is HTTP 200. A failed payment
     * (VOIDED, FAILED, COMPENSATING, RECONCILIATION_REQUIRED) throws
     * ApiException: `new SplitPaymentResult($e->getResponseBody() ?? [])`.
     * Rule violations are 422 `split_pay.validation.*` (SplitPayErrorCode).
     *
     * @throws ApiException
     */
    public function createSplitPayment(SplitPaymentRequest $request, ?string $idempotencyKey = null): SplitPaymentResult
    {
        $body = $request->toArray();
        $key = self::requireSplitIdempotencyKey('split payment', $idempotencyKey);

        try {
            return new SplitPaymentResult($this->api->request('POST', self::BASE . '/split', $body, [
                'stepUp' => true,
                'idempotencyKey' => $key,
            ]));
        } catch (ApiException $error) {
            $running = self::runningSplitResponse($error);
            if ($running !== null) {
                return new SplitPaymentResult($running);
            }
            throw $error;
        }
    }

    /**
     * One split payment with its legs: per leg method, provider, amounts
     * (charge, captured, refunded), refund state and chargeback; for the main
     * payment the money aggregate, refund and chargeback summary. The card
     * token is never returned. Another merchant's split is HTTP 404.
     *
     * @throws ApiException
     */
    public function getSplitPayment(string $splitPaymentId): SplitPaymentDetail
    {
        $response = $this->api->request('GET', self::BASE . '/split/' . rawurlencode(self::requireSplitPaymentId($splitPaymentId)));

        return new SplitPaymentDetail(Envelope::data($response));
    }

    /**
     * Split payments, newest first. Filters: `status` and `currency` (a value
     * or a list, sent as repeated parameters), `createdFrom`, `createdTo`
     * (ISO 8601), `orderId`, `ownerRef`, `limit` (1–100) and `after`
     * (SplitPaymentList::endCursor() of the previous page).
     *
     * @param array<string,mixed> $filters
     * @throws ApiException
     */
    public function listSplitPayments(array $filters = []): SplitPaymentList
    {
        $query = self::listSplitPaymentsQuery($filters);

        return new SplitPaymentList($this->api->request('GET', self::BASE . '/split' . ($query === '' ? '' : '?' . $query)));
    }

    /**
     * Refunds the main payment of a completed split payment. The amount is
     * spread over the legs: card legs first (partial refunds allowed), then
     * the other refundable legs (each in full); a non-reversible leg is never
     * refunded. The Idempotency-Key is required; retry with the same key.
     *
     * When some legs could not be refunded the gateway answers
     * `split_pay.refund.incomplete` (ApiException; `new
     * SplitRefundResult($e->getResponseBody() ?? [])` reads the per-leg
     * result). The main payment of a Checkout Form split is refunded with
     * refund(), which spreads the amount the same way.
     *
     * @throws ApiException
     */
    public function refundSplitPayment(string $splitPaymentId, int $amount, ?string $reason = null, ?string $idempotencyKey = null): SplitRefundResult
    {
        $id = self::requireSplitPaymentId($splitPaymentId);
        if ($amount < 1) {
            throw new ConfigurationException('The refund amount must be a positive integer (minor units).');
        }
        $body = ['amount' => $amount];
        if ($reason !== null && $reason !== '') {
            if (strlen($reason) > self::SPLIT_REFUND_REASON_MAX_LENGTH) {
                throw new ConfigurationException(sprintf('reason can be at most %d characters.', self::SPLIT_REFUND_REASON_MAX_LENGTH));
            }
            $body['reason'] = $reason;
        }

        return new SplitRefundResult($this->api->request('POST', self::BASE . '/split/' . rawurlencode($id) . '/refund', $body, [
            'stepUp' => true,
            'idempotencyKey' => self::requireSplitIdempotencyKey('split payment refund', $idempotencyKey),
        ]));
    }

    /**
     * The gateway answers a running split payment with HTTP 400 and the split
     * status as `code` (the payment has not failed). Only a body that is the
     * split payment itself (`data.splitPaymentId`) with a running status
     * qualifies.
     *
     * @return array<string,mixed>|null
     */
    private static function runningSplitResponse(ApiException $error): ?array
    {
        if ($error->getStatusCode() !== 400 || !in_array($error->getErrorCode(), SplitPaymentStatus::IN_PROGRESS, true)) {
            return null;
        }
        $body = $error->getResponseBody();
        $data = is_array($body) && isset($body['data']) && is_array($body['data']) ? $body['data'] : null;
        if ($data === null || !isset($data['splitPaymentId']) || !is_string($data['splitPaymentId']) || $data['splitPaymentId'] === '') {
            return null;
        }

        return $body;
    }

    private static function requireSplitPaymentId(string $splitPaymentId): string
    {
        if (trim($splitPaymentId) === '') {
            throw new ConfigurationException('splitPaymentId is required (SplitPaymentResult::splitPaymentId()).');
        }

        return $splitPaymentId;
    }

    private static function requireSplitIdempotencyKey(string $operation, ?string $key): string
    {
        $trimmed = self::requireIdempotencyKey($operation, $key);
        if (strlen($trimmed) > self::SPLIT_IDEMPOTENCY_KEY_MAX_LENGTH) {
            throw new ConfigurationException(sprintf(
                'The Idempotency-Key of %s can be at most %d characters.',
                $operation,
                self::SPLIT_IDEMPOTENCY_KEY_MAX_LENGTH
            ));
        }

        return $trimmed;
    }

    /**
     * Query string of GET /payments/split with repeated parameters for list
     * values (`status=COMPLETED&status=VOIDED`), as the gateway expects.
     *
     * @param array<string,mixed> $filters
     */
    private static function listSplitPaymentsQuery(array $filters): string
    {
        $unknown = array_diff(array_keys($filters), self::LIST_SPLIT_PAYMENTS_FILTERS);
        if ($unknown !== []) {
            throw new ConfigurationException(sprintf(
                'listSplitPayments does not take %s; filters: %s.',
                implode(', ', $unknown),
                implode(', ', self::LIST_SPLIT_PAYMENTS_FILTERS)
            ));
        }
        $pairs = [];
        foreach (self::LIST_SPLIT_PAYMENTS_FILTERS as $key) {
            $value = $filters[$key] ?? null;
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            if ($key === 'status' || $key === 'currency') {
                $values = is_array($value) ? array_values($value) : [$value];
                if (count($values) > 20) {
                    throw new ConfigurationException(sprintf('%s takes at most 20 values.', $key));
                }
                foreach ($values as $entry) {
                    $valid = $key === 'status'
                        ? in_array($entry, SplitPaymentStatus::ALL, true)
                        : is_string($entry) && preg_match('/^[A-Z]{3}$/', $entry) === 1;
                    if (!$valid) {
                        throw new ConfigurationException(sprintf('%s has an invalid value.', $key));
                    }
                    $pairs[] = rawurlencode($key) . '=' . rawurlencode((string) $entry);
                }
                continue;
            }
            if ($key === 'limit') {
                if (!is_int($value) || $value < 1 || $value > 100) {
                    throw new ConfigurationException('limit must be an integer from 1 to 100.');
                }
                $pairs[] = 'limit=' . $value;
                continue;
            }
            if (!is_string($value) || trim($value) === '') {
                throw new ConfigurationException(sprintf('%s must be a non-empty string.', $key));
            }
            $pairs[] = rawurlencode($key) . '=' . rawurlencode($value);
        }

        return implode('&', $pairs);
    }

    /**
     * @param array<string,mixed>|null $body
     */
    private function mutate(string $intentId, string $action, ?array $body, ?string $idempotencyKey): PaymentIntent
    {
        $response = $this->api->request(
            'POST',
            self::BASE . '/' . rawurlencode($intentId) . '/' . $action,
            $body,
            [
                'stepUp' => true,
                'idempotencyKey' => self::requireIdempotencyKey($action, $idempotencyKey),
            ]
        );

        return new PaymentIntent(self::dataOf($response));
    }

    /**
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    private static function dataOf(array $response): array
    {
        return isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
    }

    private static function promissoryNotesPath(string $paymentId): string
    {
        if (trim($paymentId) === '') {
            throw new ConfigurationException('paymentId is required (paymentId() of the promissory-note payment).');
        }

        return self::BASE . '/' . rawurlencode($paymentId) . '/promissory-notes';
    }

    private static function actionPath(string $paymentId, string $actionId, string $operation): string
    {
        if (trim($paymentId) === '' || trim($actionId) === '') {
            throw new ConfigurationException(
                'paymentId and actionId are required (from the payment response: paymentId() and action()->id()).'
            );
        }

        return self::BASE . '/' . rawurlencode($paymentId) . '/actions/' . rawurlencode($actionId) . '/' . $operation;
    }

    private static function requireIdempotencyKey(string $operation, ?string $key): string
    {
        $trimmed = is_string($key) ? trim($key) : '';
        if ($trimmed === '') {
            throw new ConfigurationException(sprintf(
                'A stable Idempotency-Key is required for %s. Reuse the same key when retrying the same merchant operation.',
                $operation
            ));
        }

        return $trimmed;
    }

    /**
     * `amount` is the only amount field of a payment body; a body that still
     * carries one of these (any value, null included) is refused with 400
     * `payment.amount.field_removed`, so it is refused here before sending. A
     * basket item keeps its own `price`.
     *
     * @internal shared with MarketplaceClient's raw marketplace charge.
     * @param array<string,mixed> $payload
     */
    public static function assertNoRemovedAmountFields(array $payload): void
    {
        $sent = array_values(array_filter(
            self::REMOVED_AMOUNT_FIELDS,
            static fn (string $field): bool => array_key_exists($field, $payload)
        ));
        if ($sent !== []) {
            throw new ConfigurationException(sprintf(
                '%s: removed from the payment body; send the amount only in `amount` (minor units, the basket total without the installment fee).',
                implode(', ', $sent)
            ));
        }
    }

    /**
     * `preauth` is a money instruction: only a real boolean is sent. A string
     * such as "false" is refused here instead of reaching the gateway (400).
     *
     * @internal shared with MarketplaceClient's raw marketplace charge.
     * @param array<string,mixed> $payload
     */
    public static function assertPreauthField(array $payload): void
    {
        if (array_key_exists('preauth', $payload) && !is_bool($payload['preauth'])) {
            throw new ConfigurationException('`preauth` must be a boolean (true = pre-authorization, false = immediate capture).');
        }
    }
}
