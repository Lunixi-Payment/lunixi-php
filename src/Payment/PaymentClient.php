<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

use Lunixi\Sdk\ApiClient;
use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Exception\ConfigurationException;

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
 * capture/refund/void/direct-card/card-storage mutations REQUIRE a stable
 * Idempotency-Key from the caller so cross-process retries are de-duplicated
 * by the gateway instead of creating a fresh financial operation.
 */
final class PaymentClient
{
    private const BASE = '/api/v1/payments';

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
     *
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public function createMarketplaceIntent(array $request, ?string $idempotencyKey = null): array
    {
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
     * Direct server-to-server card charge (no hosted form).
     *
     * ⚠️ PCI: the caller transmits the raw PAN, so this path is only for
     * PCI-DSS SAQ-D integrations. Most merchants should use createIntent() + the
     * hosted checkout form (SAQ-A) instead. Step-up signed.
     *
     * @param array<string,mixed> $payload paidPrice, currency, orderId, installment?,
     *   paymentChannel?, basketId?, card{cardHolderName,cardNumber,expireMonth,
     *   expireYear,cvc}, buyer{...}, billingAddress{...}, basketItems[]…
     * @param bool $threeDS true → POST /direct/3d (returns a 3-D redirect/HTML);
     *   false → POST /direct/2d (returns the final 2-D result).
     * @return array<string,mixed> Raw gateway response.
     */
    public function chargeCard(array $payload, bool $threeDS = false, ?string $idempotencyKey = null): array
    {
        self::assertDirectPaymentPayload($payload);

        return $this->api->request('POST', self::BASE . '/direct/' . ($threeDS ? '3d' : '2d'), $payload, [
            'stepUp' => true,
            'idempotencyKey' => self::requireIdempotencyKey($threeDS ? 'direct 3D payment' : 'direct 2D payment', $idempotencyKey),
        ]);
    }

    public function chargeCard2d(DirectPaymentRequest $request, ?string $idempotencyKey = null): array
    {
        return $this->chargeCard($request->toArray(), false, $idempotencyKey);
    }

    public function chargeCard3d(DirectPaymentRequest $request, ?string $idempotencyKey = null): array
    {
        return $this->chargeCard($request->toArray(), true, $idempotencyKey);
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
     * Lists payment intents.
     *
     * @param array{page?:int, limit?:int, status?:string, customerId?:string} $filters
     */
    public function list(array $filters = []): PaymentList
    {
        $query = [];
        foreach (['page', 'limit', 'status', 'customerId'] as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $query[$key] = $filters[$key];
            }
        }

        $response = $this->api->request('GET', self::BASE, null, ['query' => $query]);

        return new PaymentList($response);
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
     * @param array<string,mixed> $payload
     */
    private static function assertDirectPaymentPayload(array $payload): void
    {
        foreach (['paidPrice', 'currency', 'orderId', 'card', 'buyer', 'billingAddress', 'basketItems'] as $field) {
            if (!array_key_exists($field, $payload)) {
                throw new ConfigurationException(sprintf(
                    'Direct payments require `%s`. Use DirectPaymentRequest to build a complete payment request.',
                    $field
                ));
            }
        }

        foreach (['card', 'buyer', 'billingAddress'] as $field) {
            if (!is_array($payload[$field]) || count($payload[$field]) === 0) {
                throw new ConfigurationException(sprintf('Direct payments require a non-empty `%s` object.', $field));
            }
        }

        if (!is_array($payload['basketItems']) || count($payload['basketItems']) === 0) {
            throw new ConfigurationException('Direct payments require at least one basket item.');
        }
    }
}
