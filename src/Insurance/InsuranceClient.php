<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Insurance;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Generator;
use Lunixi\Sdk\ApiClient;
use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Payment\BasketItem;
use stdClass;

/**
 * Insurance — offer, sell and service insurance for your basket items, and
 * submit leads for the types sold by request only (`/api/v1/insurance`,
 * product INSURANCE_SERVICE).
 *
 *   listProducts                                  GET  /api/v1/insurance/products
 *   searchProducts                                POST /api/v1/insurance/products            (200, read-only)
 *   listReferenceValues / iterateReferenceValues  GET  /api/v1/insurance/reference/{kind}
 *   createQuote                                   POST /api/v1/insurance/quotes              (201)
 *   getQuote                                      GET  /api/v1/insurance/quotes/{quoteId}
 *   createOrder                                   POST /api/v1/insurance/orders              (201, Idempotency-Key)
 *   listOrders / iterateOrders                    GET  /api/v1/insurance/orders
 *   getOrder                                      GET  /api/v1/insurance/orders/{orderId}
 *   bindPayment                                   POST …/orders/{orderId}/bind-payment
 *   confirmPayment                                POST …/orders/{orderId}/confirm-payment    (Idempotency-Key)
 *   issuePolicy                                   POST …/orders/{orderId}/issue-policy       (Idempotency-Key)
 *   cancelOrder                                   POST …/orders/{orderId}/cancel             (Idempotency-Key)
 *   refundPremium                                 POST …/orders/{orderId}/refund-premium     (Idempotency-Key)
 *   createLead                                    POST /api/v1/insurance/leads               (201, Idempotency-Key)
 *   listLeads / iterateLeads                      GET  /api/v1/insurance/leads
 *   getLead                                       GET  /api/v1/insurance/leads/{leadId}
 *   getPolicyDocument                             GET  /api/v1/insurance/policies/{policyId}/document  (PDF)
 *   getPolicyReceipt                              GET  …/policies/{policyId}/receipt                   (PDF)
 *   getPolicyCertificate                          GET  …/policies/{policyId}/certificate               (PDF)
 *   getReconciliationSummary                      GET  /api/v1/insurance/reconciliation/summary
 *   listReconciliationOrders / iterate…           GET  /api/v1/insurance/reconciliation/orders
 *   exportReconciliation                          GET  /api/v1/insurance/reconciliation/export  (CSV)
 *
 * Every call is signed (step-up), reads included: an unsigned request is 401
 * `AUTH_HEADERS_MISSING`, a key without the product 403 `PRODUCT_NOT_ENABLED`.
 * The key decides the environment (TEST or LIVE).
 *
 * Request fields and query filters are checked before anything is sent,
 * because the gateway rejects an unknown field with 400: an unknown field, a
 * quote with both basket sources, a missing or malformed
 * Idempotency-Key where one is required, a non-integer amount or an unknown
 * reference kind is a ConfigurationException. The fields of an insurance
 * type's own object (`insured`, `tickets`, `accident` …) are sent as given; the gateway
 * reports a violation as 422 `insurance.validation.invalid` with every
 * `details.errors[{field, reason}]` at once. Amounts are integers in minor
 * units.
 */
final class InsuranceClient
{
    /**
     * Top-level fields of createQuote(). After the person fields come the type
     * objects, one per insurance type (InsuranceProduct::typeObject()): `tickets`
     * (a list) and the single objects `stay`, `device`, `product`, `vehicle`,
     * `trip`, `rental`, `instrument`, `event`, `accident`, `procedure`, `pet`,
     * `residence`, `abroadStay`.
     */
    public const QUOTE_FIELDS = [
        'insuranceType', 'schemaVersion', 'currency', 'paymentIntentId', 'context',
        'merchantOrderId', 'policyholder', 'insured',
        'tickets', 'stay', 'device', 'product', 'vehicle', 'trip', 'rental', 'instrument',
        'event', 'accident', 'procedure', 'pet', 'residence', 'abroadStay',
    ];

    /**
     * Top-level fields of createLead(): the type body of a quote without the
     * basket, currency and order fields, plus `shipment` and `company` (used
     * only by lead schemas) and the free `note`. A lead has no amount,
     * currency, basket item or payment.
     */
    public const LEAD_FIELDS = [
        'insuranceType', 'schemaVersion', 'policyholder', 'insured',
        'tickets', 'stay', 'device', 'product', 'vehicle', 'trip', 'rental', 'instrument',
        'event', 'accident', 'procedure', 'pet', 'residence', 'abroadStay',
        'shipment', 'company', 'note',
    ];

    /** Fields of searchProducts(). */
    public const PRODUCT_SEARCH_FIELDS = ['currency', 'insuranceType', 'context'];

    /** Fields of createOrder(). */
    public const ORDER_FIELDS = ['quoteId', 'merchantOrderId', 'consent'];

    /** Fields of the order's `consent`. */
    public const CONSENT_FIELDS = ['termsVersion', 'termsSha256', 'acceptedAt'];

    /** Fields of confirmPayment() (a premium collected outside Lunixi). */
    public const EXTERNAL_PAYMENT_FIELDS = ['externalPaymentId', 'paidAmount', 'paidCurrency', 'paidAt', 'paymentProvider'];

    /** Fields of `externalRefund` (refundPremium() for a premium collected outside Lunixi). */
    public const EXTERNAL_REFUND_FIELDS = ['externalRefundId', 'refundedAt'];

    /** Query filters each read accepts. */
    public const FILTERS = [
        'listProducts' => ['currency', 'paymentIntentId', 'insuranceType'],
        'listReferenceValues' => ['parentCode', 'limit', 'cursor'],
        'listOrders' => ['limit', 'cursor', 'paymentIntentId', 'merchantOrderId'],
        'getReconciliationSummary' => ['from', 'to', 'connectionId', 'insuranceType', 'currency'],
        'listReconciliationOrders' => ['from', 'to', 'connectionId', 'insuranceType', 'currency', 'limit', 'cursor'],
        'exportReconciliation' => ['from', 'to', 'connectionId', 'insuranceType', 'currency', 'cursor'],
        'listLeads' => ['limit', 'cursor', 'insuranceType', 'status'],
    ];

    /** `Idempotency-Key` of the insurance routes: 8–200 printable ASCII characters, no spaces. */
    public const IDEMPOTENCY_KEY_PATTERN = '/^[\x21-\x7E]{8,200}$/';

    /** Largest page (`limit` 1–100). */
    public const MAX_LIMIT = 100;

    /** Most basket items one `context.items` may carry. */
    public const MAX_CONTEXT_ITEMS = 100;

    /** Orders in one reconciliation CSV file; a longer export ends with a continuation line. */
    public const EXPORT_ORDER_CAP = 20000;

    /** Longest lead `note` (characters). */
    public const LEAD_NOTE_MAX_LENGTH = 1000;

    private const BASE = '/api/v1/insurance';
    private const CURSOR_MAX_LENGTH = 512;
    private const TEXT_MAX_LENGTH = 128;
    private const CODE_MAX_LENGTH = 64;
    private const PDF_ACCEPT = 'application/pdf, application/json';
    private const CSV_ACCEPT = 'text/csv, application/json';
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private ApiClient $api;

    public function __construct(ApiClient $api)
    {
        $this->api = $api;
    }

    /**
     * Insurance types offered for a payment's basket, read from the items sent
     * when the payment was created. Without `paymentIntentId` it lists the
     * insurance types sold without a basket item (`basketItemIds()` empty). An
     * empty list means insurance is not offered; it is not an error.
     *
     * @param array<string,mixed> $filters currency (ISO 4217, required), paymentIntentId?, insuranceType?.
     * @return InsuranceProduct[]
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function listProducts(array $filters): array
    {
        $query = self::query($filters, 'listProducts');
        self::requireFilters($query, ['currency'], 'listProducts');

        return self::products($this->read('/products', $query));
    }

    /**
     * Insurance types offered for basket items before the payment exists
     * (read-only). Send the items you will send in the payment.
     *
     * @param array<string,mixed> $request currency, context (`['items' => BasketItem[]|array[]]`), insuranceType?.
     * @return InsuranceProduct[]
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function searchProducts(array $request): array
    {
        self::assertKeys($request, self::PRODUCT_SEARCH_FIELDS, 'searchProducts request');
        $payload = ['currency' => self::requireCurrency($request['currency'] ?? null, 'currency')];
        if (self::present($request['insuranceType'] ?? null)) {
            $payload['insuranceType'] = self::requireText($request['insuranceType'], 'insuranceType', self::CODE_MAX_LENGTH);
        }
        $payload['context'] = self::context($request['context'] ?? null);

        return self::products($this->api->request('POST', self::BASE . '/products', $payload, ['stepUp' => true]));
    }

    /**
     * Lunixi reference codes of one kind, for the type fields that take a code.
     *
     * @param string $kind One of the InsuranceReferenceKind constants.
     * @param array<string,mixed> $filters parentCode (province for DISTRICT, brand for DEVICE_MODEL and
     *   VEHICLE_MODEL, bank for BANK_BRANCH, CAT or DOG for PET_BREED), limit (1–100), cursor.
     * @return InsurancePage<InsuranceReferenceValue>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function listReferenceValues(string $kind, array $filters = []): InsurancePage
    {
        $path = '/reference/' . rawurlencode(self::referenceKind($kind));

        return new InsurancePage(
            $this->read($path, self::query($filters, 'listReferenceValues')),
            static fn (array $row): InsuranceReferenceValue => new InsuranceReferenceValue($row)
        );
    }

    /**
     * @param array<string,mixed> $filters See {@see listReferenceValues()}.
     * @return Generator<int,InsuranceReferenceValue>
     */
    public function iterateReferenceValues(string $kind, array $filters = []): Generator
    {
        self::referenceKind($kind);

        return self::paginate(fn (array $page): InsurancePage => $this->listReferenceValues($kind, $page), $filters);
    }

    /**
     * Prices an insurance type (HTTP 201). The basket is either a payment's
     * recorded basket (`paymentIntentId`) or the items you will send in the
     * payment (`context.items`), not both. To collect the premium in your Lunixi
     * payment, quote with `context.items`. Send neither for an insurance type
     * sold without a basket item: the insured value is then in the type object
     * (a type sold only with a basket item answers 412
     * `insurance.type.not_available`).
     *
     * Every option names its insurance company (InsuranceQuoteOption::underwriter());
     * a company the request could not be priced with is in
     * InsuranceQuoteSet::excludedOptions() with the missing field paths and does
     * not stop the other options. When no company can be priced, the call fails
     * (422 `insurance.validation.invalid` with every missing field).
     *
     * `policyholder`, `insured` and the type object (InsuranceProduct::typeObject(),
     * e.g. `tickets` for TICKET_CANCELLATION) follow the type's schema (InsuranceProduct::fields()).
     * Policy-stage fields (e.g. PNR, ticket number, the insured's e-mail and
     * phone) are sent here too: there is no later call to add them.
     *
     * The Idempotency-Key is optional and only correlates traces: every call is
     * a new, time-limited price.
     *
     * @param array<string,mixed> $request insuranceType, currency, paymentIntentId? | context?, schemaVersion?,
     *   merchantOrderId? (≤ 128), policyholder?, insured?, the type object (tickets?, stay?, device?, product?,
     *   vehicle?, trip?, rental?, instrument?, event?, accident?, procedure?).
     * @throws ConfigurationException
     * @throws ApiException 422 `insurance.validation.invalid` (`details.errors`).
     */
    public function createQuote(array $request, ?string $idempotencyKey = null): InsuranceQuoteSet
    {
        self::assertKeys($request, self::QUOTE_FIELDS, 'createQuote request');
        $hasPayment = self::present($request['paymentIntentId'] ?? null);
        $hasItems = self::present($request['context'] ?? null);
        if ($hasPayment && $hasItems) {
            throw new ConfigurationException(
                "Send the basket either as 'paymentIntentId' (a payment that exists) or as 'context.items' (before the payment), not both. Send neither for an insurance type sold without a basket item."
            );
        }

        $payload = [];
        foreach ($request as $key => $value) {
            if (!self::present($value)) {
                continue;
            }
            switch ($key) {
                case 'insuranceType':
                    $payload[$key] = self::requireText($value, 'insuranceType', self::CODE_MAX_LENGTH);
                    break;
                case 'currency':
                    $payload[$key] = self::requireCurrency($value, 'currency');
                    break;
                case 'paymentIntentId':
                    $payload[$key] = self::requireUuid($value, 'paymentIntentId');
                    break;
                case 'context':
                    $payload[$key] = self::context($value);
                    break;
                case 'merchantOrderId':
                    $payload[$key] = self::requireText($value, 'merchantOrderId', self::TEXT_MAX_LENGTH);
                    break;
                default:
                    $payload[$key] = $value;
            }
        }
        self::requireText($payload['insuranceType'] ?? null, 'insuranceType');
        self::requireCurrency($payload['currency'] ?? null, 'currency');

        $response = $this->api->request('POST', self::BASE . '/quotes', $payload, [
            'stepUp' => true,
            'idempotencyKey' => self::optionalIdempotencyKey($idempotencyKey),
        ]);

        return new InsuranceQuoteSet(self::dataOf($response));
    }

    /** @throws ApiException 404 `insurance.quote.not_found` for an unknown or another merchant's quote. */
    public function getQuote(string $quoteId): InsuranceQuoteOption
    {
        $path = '/quotes/' . rawurlencode(self::requireText($quoteId, 'quoteId'));

        return new InsuranceQuoteOption(self::dataOf($this->read($path)));
    }

    /**
     * Turns a quote option into an order (HTTP 201) and records the buyer's
     * consent. No provider call and no money movement. The same key with the
     * same body returns the recorded order; with another body 409
     * `insurance.idempotency.conflict`.
     *
     * @param array<string,mixed> $request quoteId, consent ({@see consentFromQuote()}), merchantOrderId?
     *   (defaults to the quote's; must match it when both are set).
     * @throws ConfigurationException
     * @throws ApiException 409 `insurance.quote.expired`, `insurance.order.state_conflict`.
     */
    public function createOrder(array $request, string $idempotencyKey): InsuranceOrder
    {
        $key = self::requireIdempotencyKey('insurance order create', $idempotencyKey);
        self::assertKeys($request, self::ORDER_FIELDS, 'createOrder request');
        $consent = $request['consent'] ?? null;
        if (!is_array($consent) || ($consent !== [] && array_values($consent) === $consent)) {
            throw new ConfigurationException('consent must be an array keyed by field name.');
        }
        self::assertKeys($consent, self::CONSENT_FIELDS, 'consent');

        $payload = ['quoteId' => self::requireText($request['quoteId'] ?? null, 'quoteId')];
        if (self::present($request['merchantOrderId'] ?? null)) {
            $payload['merchantOrderId'] = self::requireText($request['merchantOrderId'], 'merchantOrderId', self::TEXT_MAX_LENGTH);
        }
        $payload['consent'] = [
            'termsVersion' => self::requireText($consent['termsVersion'] ?? null, 'consent.termsVersion'),
            'termsSha256' => self::requireText($consent['termsSha256'] ?? null, 'consent.termsSha256'),
            'acceptedAt' => self::isoTime($consent['acceptedAt'] ?? null, 'consent.acceptedAt'),
        ];

        $response = $this->api->request('POST', self::BASE . '/orders', $payload, ['stepUp' => true, 'idempotencyKey' => $key]);

        return new InsuranceOrder(self::dataOf($response));
    }

    /**
     * Your insurance orders, newest first.
     *
     * @param array<string,mixed> $filters limit (1–100), cursor, paymentIntentId, merchantOrderId.
     * @return InsurancePage<InsuranceOrder>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function listOrders(array $filters = []): InsurancePage
    {
        return new InsurancePage(
            $this->read('/orders', self::query($filters, 'listOrders')),
            static fn (array $row): InsuranceOrder => new InsuranceOrder($row)
        );
    }

    /**
     * @param array<string,mixed> $filters See {@see listOrders()}.
     * @return Generator<int,InsuranceOrder>
     */
    public function iterateOrders(array $filters = []): Generator
    {
        return self::paginate(fn (array $page): InsurancePage => $this->listOrders($page), $filters);
    }

    /** @throws ApiException 404 `insurance.order.not_found` for an unknown or another merchant's order. */
    public function getOrder(string $orderId): InsuranceOrder
    {
        return new InsuranceOrder(self::dataOf($this->read(self::orderPath($orderId))));
    }

    /**
     * Binds an order to the Lunixi payment that collects its premium. When you
     * collect it (`premiumCollectedBy` MERCHANT) the payment basket must hold
     * the premium item ({@see premiumBasketItem()}); when the provider collects
     * it, it must not. Binding the same payment again returns the order. Once
     * the payment is captured the policy is requested on its own.
     *
     * @throws ConfigurationException
     * @throws ApiException 409 `insurance.order.premium_item_mismatch` (`details.reason`).
     */
    public function bindPayment(string $orderId, string $paymentIntentId, ?string $idempotencyKey = null): InsuranceOrder
    {
        $path = self::BASE . self::orderPath($orderId) . '/bind-payment';
        $response = $this->api->request('POST', $path, ['paymentIntentId' => self::requireUuid($paymentIntentId, 'paymentIntentId')], [
            'stepUp' => true,
            'idempotencyKey' => self::optionalIdempotencyKey($idempotencyKey),
        ]);

        return new InsuranceOrder(self::dataOf($response));
    }

    /**
     * Records a premium you collected outside Lunixi. `paidAmount` and
     * `paidCurrency` must equal the order's premium. Repeating the same
     * `externalPaymentId` returns the order. Request the policy with
     * {@see issuePolicy()} afterwards.
     *
     * @param array<string,mixed> $payment externalPaymentId (1–128 printable ASCII), paidAmount (int, minor
     *   units), paidCurrency, paidAt (DateTimeInterface or ISO-8601 with a time zone), paymentProvider? (≤ 64).
     * @throws ConfigurationException
     * @throws ApiException 422 `insurance.validation.invalid` (premium_amount_mismatch).
     */
    public function confirmPayment(string $orderId, array $payment, string $idempotencyKey): InsuranceOrder
    {
        $key = self::requireIdempotencyKey('insurance payment confirmation', $idempotencyKey);
        self::assertKeys($payment, self::EXTERNAL_PAYMENT_FIELDS, 'payment');
        $body = [
            'externalPaymentId' => self::requireText($payment['externalPaymentId'] ?? null, 'payment.externalPaymentId', self::TEXT_MAX_LENGTH),
            'paidAmount' => self::requirePositiveMinor($payment['paidAmount'] ?? null, 'payment.paidAmount'),
            'paidCurrency' => self::requireCurrency($payment['paidCurrency'] ?? null, 'payment.paidCurrency'),
            'paidAt' => self::isoTime($payment['paidAt'] ?? null, 'payment.paidAt'),
        ];
        if (self::present($payment['paymentProvider'] ?? null)) {
            $body['paymentProvider'] = self::requireText($payment['paymentProvider'], 'payment.paymentProvider', self::CODE_MAX_LENGTH);
        }

        $path = self::BASE . self::orderPath($orderId) . '/confirm-payment';
        $response = $this->api->request('POST', $path, ['payment' => $body], ['stepUp' => true, 'idempotencyKey' => $key]);

        return new InsuranceOrder(self::dataOf($response));
    }

    /**
     * Requests the policy of an order whose premium you collected outside
     * Lunixi (`policyStatus` PENDING; the result arrives by webhook). With a
     * Lunixi payment the policy is requested on its own once the premium is
     * collected.
     *
     * @throws ConfigurationException
     * @throws ApiException 412 `insurance.premium.not_captured` before the premium is collected.
     */
    public function issuePolicy(string $orderId, string $idempotencyKey): InsuranceOrder
    {
        return $this->orderAction($orderId, '/issue-policy', self::requireIdempotencyKey('insurance policy issue', $idempotencyKey));
    }

    /**
     * Opens a cancellation at the provider for every issued policy of the order
     * (`policyStatus` CANCEL_PENDING; `insurance.policy.cancelled` confirms it).
     * Cancelling does not refund the premium: call {@see refundPremium()} after it.
     *
     * @throws ConfigurationException
     * @throws ApiException 409 `insurance.order.state_conflict` (`details.reason`).
     */
    public function cancelOrder(string $orderId, string $idempotencyKey): InsuranceOrder
    {
        return $this->orderAction($orderId, '/cancel', self::requireIdempotencyKey('insurance order cancel', $idempotencyKey));
    }

    /**
     * Refunds the premium. With a Lunixi payment pass `[]`: the premium is
     * refunded from that payment (`refundStatus` PENDING, then
     * `insurance.refund.completed`). For a premium collected outside Lunixi,
     * report the refund you made:
     * `['externalRefund' => ['externalRefundId' => …, 'refundedAt' => …]]`.
     * An in-force policy must be cancelled first (409, `policy_active`).
     *
     * @param array<string,mixed> $options externalRefund? (externalRefundId, refundedAt).
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function refundPremium(string $orderId, array $options, string $idempotencyKey): InsuranceOrder
    {
        $key = self::requireIdempotencyKey('insurance premium refund', $idempotencyKey);
        self::assertKeys($options, ['externalRefund'], 'refundPremium options');

        // An empty body is the JSON object `{}`, as the gateway's DTO expects.
        $payload = new stdClass();
        if (self::present($options['externalRefund'] ?? null)) {
            $refund = $options['externalRefund'];
            if (!is_array($refund)) {
                throw new ConfigurationException('externalRefund must be an array keyed by field name.');
            }
            self::assertKeys($refund, self::EXTERNAL_REFUND_FIELDS, 'externalRefund');
            $payload->externalRefund = [
                'externalRefundId' => self::requireText($refund['externalRefundId'] ?? null, 'externalRefund.externalRefundId', self::TEXT_MAX_LENGTH),
                'refundedAt' => self::isoTime($refund['refundedAt'] ?? null, 'externalRefund.refundedAt'),
            ];
        }

        $path = self::BASE . self::orderPath($orderId) . '/refund-premium';
        $response = $this->api->request('POST', $path, $payload, ['stepUp' => true, 'idempotencyKey' => $key]);

        return new InsuranceOrder(self::dataOf($response));
    }

    /**
     * Submits a lead (HTTP 201) for an insurance type sold by request only: the
     * provider gives no quote, so there is no premium, order, policy or
     * document. The lead is sent to the provider with your own connection in
     * this call. `policyholder`, the type object and an optional `note` follow
     * the type's lead schema; a violation is 422 `insurance.validation.invalid`
     * with every `details.errors[{field, reason}]` at once.
     *
     * The answer's status is SUBMITTED (the provider received it, followed by
     * the `insurance.lead.submitted` webhook) or UNKNOWN (the outcome is not
     * known; the lead is not sent again). The same key with the same body
     * returns the same lead; with another body 409 `insurance.idempotency.conflict`.
     *
     * @param array<string,mixed> $request insuranceType, schemaVersion?, policyholder?, the type object
     *   (vehicle? for MOTOR_THIRD_PARTY_LIABILITY and MOTOR_COMPREHENSIVE; shipment?, vehicle?, company? for
     *   CARGO_TRANSIT; any other of {@see LEAD_FIELDS}), note? (free text, ≤ 1000 characters, sent as given).
     * @throws ConfigurationException
     * @throws ApiException 422 `insurance.lead.rejected`, 412 `insurance.type.not_available`.
     */
    public function createLead(array $request, string $idempotencyKey): InsuranceLead
    {
        $key = self::requireIdempotencyKey('insurance lead submit', $idempotencyKey);
        self::assertKeys($request, self::LEAD_FIELDS, 'createLead request');

        $payload = [];
        foreach ($request as $field => $value) {
            if (!self::present($value)) {
                continue;
            }
            switch ($field) {
                case 'insuranceType':
                    $payload[$field] = self::requireText($value, 'insuranceType', self::CODE_MAX_LENGTH);
                    break;
                case 'note':
                    $payload[$field] = self::leadNote($value);
                    break;
                default:
                    $payload[$field] = $value;
            }
        }
        self::requireText($payload['insuranceType'] ?? null, 'insuranceType');

        $response = $this->api->request('POST', self::BASE . '/leads', $payload, ['stepUp' => true, 'idempotencyKey' => $key]);

        return new InsuranceLead(self::dataOf($response));
    }

    /**
     * Your leads, newest first. Personal data is masked in requestMasked().
     *
     * @param array<string,mixed> $filters limit (1–100), cursor, insuranceType, status (InsuranceLeadStatus).
     * @return InsurancePage<InsuranceLead>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function listLeads(array $filters = []): InsurancePage
    {
        return new InsurancePage(
            $this->read('/leads', self::query($filters, 'listLeads')),
            static fn (array $row): InsuranceLead => new InsuranceLead($row)
        );
    }

    /**
     * @param array<string,mixed> $filters See {@see listLeads()}.
     * @return Generator<int,InsuranceLead>
     */
    public function iterateLeads(array $filters = []): Generator
    {
        return self::paginate(fn (array $page): InsurancePage => $this->listLeads($page), $filters);
    }

    /** @throws ApiException 404 `insurance.lead.not_found` for an unknown or another merchant's lead. */
    public function getLead(string $leadId): InsuranceLead
    {
        $path = '/leads/' . rawurlencode(self::requireText($leadId, 'leadId'));

        return new InsuranceLead(self::dataOf($this->read($path)));
    }

    /**
     * The policy PDF. It is read from the provider on every call and is
     * available while the policy is in force (ISSUED, CANCEL_PENDING).
     *
     * @throws ConfigurationException
     * @throws ApiException 503 `insurance.document.unavailable` is temporary — retry later.
     */
    public function getPolicyDocument(string $policyId): InsurancePolicyDocument
    {
        return $this->policyFile($policyId, 'document');
    }

    /**
     * The provider's receipt for the policy premium (PDF), only for products
     * that issue one. Same availability as {@see getPolicyDocument()}.
     *
     * @throws ConfigurationException
     * @throws ApiException 412 `insurance.document.not_supported`, 503 `insurance.document.unavailable`.
     */
    public function getPolicyReceipt(string $policyId): InsurancePolicyDocument
    {
        return $this->policyFile($policyId, 'receipt');
    }

    /**
     * The provider's certificate of the policy (PDF; for example the Green Card
     * of MOTOR_GREEN_CARD), only for products that issue one. Same availability
     * as {@see getPolicyDocument()}.
     *
     * @throws ConfigurationException
     * @throws ApiException 412 `insurance.document.not_supported`, 503 `insurance.document.unavailable`.
     */
    public function getPolicyCertificate(string $policyId): InsurancePolicyDocument
    {
        return $this->policyFile($policyId, 'certificate');
    }

    /**
     * Reconciliation totals grouped by provider connection, currency and
     * `premiumCollectedBy`. `from` (inclusive) and `to` (exclusive) are
     * required, on the order creation time, at most 366 days apart.
     *
     * @param array<string,mixed> $filters from, to (DateTimeInterface or ISO-8601 with a time zone),
     *   connectionId, insuranceType, currency.
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function getReconciliationSummary(array $filters): InsuranceReconciliationSummary
    {
        $query = self::query($filters, 'getReconciliationSummary');
        self::requireFilters($query, ['from', 'to'], 'getReconciliationSummary');

        return new InsuranceReconciliationSummary(self::dataOf($this->read('/reconciliation/summary', $query)));
    }

    /**
     * The orders and policies behind the summary for the same filter, newest first.
     *
     * @param array<string,mixed> $filters See {@see getReconciliationSummary()}, plus limit and cursor.
     * @return InsurancePage<InsuranceReconciliationOrder>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function listReconciliationOrders(array $filters): InsurancePage
    {
        $query = self::query($filters, 'listReconciliationOrders');
        self::requireFilters($query, ['from', 'to'], 'listReconciliationOrders');

        return new InsurancePage(
            $this->read('/reconciliation/orders', $query),
            static fn (array $row): InsuranceReconciliationOrder => new InsuranceReconciliationOrder($row)
        );
    }

    /**
     * @param array<string,mixed> $filters See {@see listReconciliationOrders()}.
     * @return Generator<int,InsuranceReconciliationOrder>
     */
    public function iterateReconciliationOrders(array $filters): Generator
    {
        return self::paginate(fn (array $page): InsurancePage => $this->listReconciliationOrders($page), $filters);
    }

    /**
     * The reconciliation report as CSV. A file holds at most
     * {@see EXPORT_ORDER_CAP} orders; when it was cut the result is
     * `truncated()` and `nextCursor()` continues it (pass it as `cursor`).
     *
     * @param array<string,mixed> $filters See {@see getReconciliationSummary()}, plus cursor.
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function exportReconciliation(array $filters): InsuranceReconciliationExport
    {
        $query = self::query($filters, 'exportReconciliation');
        self::requireFilters($query, ['from', 'to'], 'exportReconciliation');
        $response = $this->api->requestBinary('GET', self::BASE . '/reconciliation/export', [
            'stepUp' => true,
            'accept' => self::CSV_ACCEPT,
            'query' => $query,
        ]);

        return new InsuranceReconciliationExport((string) $response->header('content-type'), $response->body());
    }

    /**
     * `consent` for {@see createOrder()}: the quote option's terms unchanged and
     * the moment the buyer accepted them (not in the future).
     *
     * @param InsuranceQuoteOption|array<string,mixed> $option An option returned by createQuote() or getQuote().
     * @param DateTimeInterface|string $acceptedAt DateTimeInterface or an ISO-8601 string with a time zone.
     * @return array{termsVersion:string,termsSha256:string,acceptedAt:string}
     * @throws ConfigurationException
     */
    public static function consentFromQuote($option, $acceptedAt): array
    {
        $raw = $option instanceof InsuranceQuoteOption ? $option->raw() : $option;
        $terms = is_array($raw) ? ($raw['terms'] ?? null) : null;
        if (!is_array($terms)) {
            throw new ConfigurationException("The quote option has no 'terms': pass an option returned by createQuote() or getQuote().");
        }

        return [
            'termsVersion' => self::requireText($terms['version'] ?? null, 'terms.version'),
            'termsSha256' => self::requireText($terms['sha256'] ?? null, 'terms.sha256'),
            'acceptedAt' => self::isoTime($acceptedAt, 'acceptedAt'),
        ];
    }

    /**
     * The premium basket item of an order whose premium you collect
     * (`premiumCollectedBy` MERCHANT): `id` = orderId, `itemType` VIRTUAL,
     * `price` = premiumAmount. Add it to the payment basket next to the insured
     * items and add the premium to the payment `amount`; for insurance sold
     * without a basket item it can be the only item. It carries no insurance
     * field. A PROVIDER order has no premium item.
     *
     * @param InsuranceOrder|array<string,mixed> $order
     * @throws ConfigurationException
     */
    public static function premiumBasketItem($order, string $name, string $category1, ?string $category2 = null): BasketItem
    {
        $raw = $order instanceof InsuranceOrder ? $order->raw() : $order;
        if (!is_array($raw)) {
            throw new ConfigurationException('order must be an InsuranceOrder or its array.');
        }
        if (($raw['premiumCollectedBy'] ?? null) !== InsurancePremiumCollectedBy::MERCHANT) {
            throw new ConfigurationException(
                'Only an order whose premium you collect (premiumCollectedBy MERCHANT) has a premium basket item.'
            );
        }

        $item = [
            'id' => self::requireText($raw['orderId'] ?? null, 'order.orderId'),
            'price' => self::requirePositiveMinor($raw['premiumAmount'] ?? null, 'order.premiumAmount'),
            'name' => self::requireText($name, 'name'),
            'category1' => self::requireText($category1, 'category1'),
            'itemType' => BasketItem::TYPE_VIRTUAL,
        ];
        if (self::present($category2)) {
            $item['category2'] = self::requireText($category2, 'category2');
        }

        return new BasketItem($item);
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /** A policy file (`document`, `receipt`, `certificate`): PDF bytes, the JSON envelope only on an error. */
    private function policyFile(string $policyId, string $file): InsurancePolicyDocument
    {
        $path = self::BASE . '/policies/' . rawurlencode(self::requireText($policyId, 'policyId')) . '/' . $file;
        $response = $this->api->requestBinary('GET', $path, ['stepUp' => true, 'accept' => self::PDF_ACCEPT]);

        return new InsurancePolicyDocument((string) $response->header('content-type'), $response->body());
    }

    /**
     * The lead `note` is free text sent as given (not trimmed).
     *
     * @param mixed $value
     */
    private static function leadNote($value): string
    {
        if (!is_string($value)) {
            throw new ConfigurationException("'note' must be a string.");
        }
        // Characters, not bytes (no mbstring dependency).
        if ((int) preg_match_all('/./us', $value) > self::LEAD_NOTE_MAX_LENGTH) {
            throw new ConfigurationException("'note' must be at most " . self::LEAD_NOTE_MAX_LENGTH . ' characters.');
        }

        return $value;
    }

    private function orderAction(string $orderId, string $action, string $idempotencyKey): InsuranceOrder
    {
        $response = $this->api->request('POST', self::BASE . self::orderPath($orderId) . $action, null, [
            'stepUp' => true,
            'idempotencyKey' => $idempotencyKey,
        ]);

        return new InsuranceOrder(self::dataOf($response));
    }

    /**
     * Every insurance route is behind SignatureGuard: reads are signed too.
     *
     * @param array<string,scalar> $query
     * @return array<string,mixed>
     * @throws ApiException
     */
    private function read(string $path, array $query = []): array
    {
        $options = ['stepUp' => true];
        if ($query !== []) {
            $options['query'] = $query;
        }

        return $this->api->request('GET', self::BASE . $path, null, $options);
    }

    /**
     * @param callable(array<string,mixed>):InsurancePage<mixed> $fetchPage
     * @param array<string,mixed> $filters
     * @return Generator<int,mixed>
     */
    private static function paginate(callable $fetchPage, array $filters): Generator
    {
        do {
            $page = $fetchPage($filters);
            foreach ($page->items() as $item) {
                yield $item;
            }
            $filters['cursor'] = $page->nextCursor();
        } while ($page->hasMore());
    }

    /**
     * @param array<string,mixed> $response
     * @return InsuranceProduct[]
     */
    private static function products(array $response): array
    {
        $rows = self::dataOf($response)['products'] ?? [];
        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_map(
            static fn (array $row): InsuranceProduct => new InsuranceProduct($row),
            array_values(array_filter($rows, 'is_array'))
        ));
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,scalar>
     */
    private static function query(array $filters, string $route): array
    {
        $allowed = self::FILTERS[$route];
        $unknown = array_diff(array_map('strval', array_keys($filters)), $allowed);
        if ($unknown !== []) {
            throw new ConfigurationException(sprintf(
                'Unknown insurance filter(s): %s. Allowed: %s. The gateway rejects unknown query fields with 400.',
                implode(', ', $unknown),
                implode(', ', $allowed)
            ));
        }

        $query = [];
        foreach ($allowed as $key) {
            $value = $filters[$key] ?? null;
            if (!self::present($value)) {
                continue;
            }
            $query[$key] = self::filterValue($key, $value);
        }

        return $query;
    }

    /**
     * @param mixed $value
     * @return scalar
     */
    private static function filterValue(string $key, $value)
    {
        switch ($key) {
            case 'limit':
                if (!is_int($value) || $value < 1 || $value > self::MAX_LIMIT) {
                    throw new ConfigurationException("'limit' must be an integer from 1 to " . self::MAX_LIMIT . '.');
                }
                return $value;
            case 'cursor':
                if (!is_string($value) || strlen($value) > self::CURSOR_MAX_LENGTH) {
                    throw new ConfigurationException("'cursor' must be the nextCursor of the previous page, passed back unchanged.");
                }
                return $value;
            case 'currency':
                return self::requireCurrency($value, $key);
            case 'paymentIntentId':
            case 'connectionId':
                return self::requireUuid($value, $key);
            case 'from':
            case 'to':
                return self::isoTime($value, $key);
            case 'insuranceType':
            case 'parentCode':
                return self::requireText($value, $key, self::CODE_MAX_LENGTH);
            case 'merchantOrderId':
                return self::requireText($value, $key, self::TEXT_MAX_LENGTH);
            case 'status':
                if (!in_array($value, InsuranceLeadStatus::ALL, true)) {
                    throw new ConfigurationException("'status' must be one of " . implode(', ', InsuranceLeadStatus::ALL) . '.');
                }
                return $value;
        }

        throw new ConfigurationException("Unknown insurance filter '{$key}'.");
    }

    /**
     * @param array<string,scalar> $query
     * @param string[] $required
     */
    private static function requireFilters(array $query, array $required, string $route): void
    {
        $missing = array_values(array_filter($required, static fn (string $key): bool => !array_key_exists($key, $query)));
        if ($missing !== []) {
            throw new ConfigurationException(sprintf(
                '%s requires %s.',
                $route,
                implode(' and ', array_map(static fn (string $key): string => "'{$key}'", $missing))
            ));
        }
    }

    private static function referenceKind(string $kind): string
    {
        if (!in_array($kind, InsuranceReferenceKind::ALL, true)) {
            throw new ConfigurationException('Reference kind must be one of ' . implode(', ', InsuranceReferenceKind::ALL) . '.');
        }

        return $kind;
    }

    /**
     * `context: { items }` — the same basket items the payment carries
     * (1–100, BasketItem or arrays).
     *
     * @param mixed $value
     * @return array{items:array<int,array<string,mixed>>}
     */
    private static function context($value): array
    {
        if (!self::present($value)) {
            throw new ConfigurationException("'context.items' is required.");
        }
        if (!is_array($value)) {
            throw new ConfigurationException("'context' must be an array with 'items'.");
        }
        self::assertKeys($value, ['items'], 'context');
        $items = $value['items'] ?? null;
        if (!is_array($items) || $items === [] || array_values($items) !== $items || count($items) > self::MAX_CONTEXT_ITEMS) {
            throw new ConfigurationException("'context.items' must be a list of 1 to " . self::MAX_CONTEXT_ITEMS . ' basket items.');
        }

        $out = [];
        foreach ($items as $item) {
            if ($item instanceof BasketItem) {
                $out[] = $item->toArray();
            } elseif (is_array($item)) {
                $out[] = $item;
            } else {
                throw new ConfigurationException("'context.items' must hold BasketItem objects or arrays.");
            }
        }

        return ['items' => $out];
    }

    private static function optionalIdempotencyKey(?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        return self::assertIdempotencyKeyFormat(trim($key));
    }

    private static function requireIdempotencyKey(string $operation, string $key): string
    {
        $value = trim($key);
        if ($value === '') {
            throw new ConfigurationException(
                "A stable Idempotency-Key is required for {$operation}. Reuse the same key when retrying the same operation."
            );
        }

        return self::assertIdempotencyKeyFormat($value);
    }

    private static function assertIdempotencyKeyFormat(string $value): string
    {
        if (preg_match(self::IDEMPOTENCY_KEY_PATTERN, $value) !== 1) {
            throw new ConfigurationException('Idempotency-Key must be 8-200 printable ASCII characters without spaces.');
        }

        return $value;
    }

    /**
     * @param array<int|string,mixed> $object
     * @param string[] $allowed
     */
    private static function assertKeys(array $object, array $allowed, string $field): void
    {
        $unknown = array_diff(array_map('strval', array_keys($object)), $allowed);
        if ($unknown !== []) {
            throw new ConfigurationException(sprintf(
                'Unknown %s field(s): %s. Allowed: %s. The gateway rejects unknown fields with 400.',
                $field,
                implode(', ', $unknown),
                implode(', ', $allowed)
            ));
        }
    }

    /** @param mixed $value */
    private static function requireText($value, string $field, ?int $maxLength = null): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new ConfigurationException("{$field} is required.");
        }
        $text = trim($value);
        // Characters, not bytes (no mbstring dependency).
        if ($maxLength !== null && (int) preg_match_all('/./us', $text) > $maxLength) {
            throw new ConfigurationException("{$field} must be at most {$maxLength} characters.");
        }

        return $text;
    }

    /** @param mixed $value */
    private static function requireCurrency($value, string $field): string
    {
        $currency = is_string($value) ? strtoupper(trim($value)) : '';
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new ConfigurationException("'{$field}' must be an ISO 4217 code such as TRY.");
        }

        return $currency;
    }

    /** @param mixed $value */
    private static function requireUuid($value, string $field): string
    {
        $id = is_string($value) ? trim($value) : '';
        if (preg_match(self::UUID_PATTERN, $id) !== 1) {
            throw new ConfigurationException("'{$field}' must be a Lunixi id (UUID).");
        }

        return $id;
    }

    /** @param mixed $value */
    private static function requirePositiveMinor($value, string $field): int
    {
        if (!is_int($value) || $value < 1) {
            throw new ConfigurationException("'{$field}' must be an integer of at least 1 in minor units (4900 = 49.00 TRY).");
        }

        return $value;
    }

    /**
     * A DateTimeInterface (sent in UTC with milliseconds) or an ISO-8601
     * string with a time zone; a value without a zone is rejected by the
     * gateway (422 `timezone_required`).
     *
     * @param mixed $value
     */
    private static function isoTime($value, string $field): string
    {
        if ($value instanceof DateTimeInterface) {
            $utc = DateTimeImmutable::createFromFormat('U.u', $value->format('U.u'), new DateTimeZone('UTC'));
            if ($utc === false) {
                throw new ConfigurationException("'{$field}' is not a valid time.");
            }
            return $utc->format('Y-m-d\TH:i:s.v\Z');
        }
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        throw new ConfigurationException("'{$field}' must be a DateTimeInterface or an ISO-8601 string with a time zone.");
    }

    /** Path below BASE. */
    private static function orderPath(string $orderId): string
    {
        return '/orders/' . rawurlencode(self::requireText($orderId, 'orderId'));
    }

    /** @param mixed $value */
    private static function present($value): bool
    {
        return $value !== null && $value !== '' && $value !== [];
    }

    /**
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    private static function dataOf(array $response): array
    {
        return isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
    }
}
