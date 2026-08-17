<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Marketplace;

use Lunixi\Sdk\ApiClient;
use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Payment\CheckoutIntent;
use Lunixi\Sdk\Payment\CreateIntentRequest;

/**
 * Marketplace (alt bayi split) operations.
 *
 *   Split checkout (hosted form):
 *     createCheckoutIntent  POST /api/v1/payments/marketplace/intents (step-up)
 *   Sub-dealer management (bearer):
 *     createDealer / listDealers / getDealer / updateDealerStatus / onboardingToken
 *   Reporting (bearer):
 *     payoutLedger / transactions / commissionRules
 *
 * Split is AGGREGATE per seller: the caller groups basket items by vendor, sums
 * each vendor's total into a MarketplaceSeller, and passes one leg per vendor.
 */
final class MarketplaceClient
{
    private const PAY_BASE = '/api/v1/payments/marketplace';
    private const BASE = '/api/v1/marketplace';

    public const POLICY_ALL_OR_NOTHING = 'ALL_OR_NOTHING';
    public const POLICY_PARTIAL_ALLOWED = 'PARTIAL_ALLOWED';

    private ApiClient $api;

    public function __construct(ApiClient $api)
    {
        $this->api = $api;
    }

    /**
     * Creates a marketplace split checkout intent (hosted form). Returns the
     * browser token + checkoutFormContent, the same as a normal checkout — but
     * the per-seller split is gated + persisted at init, applied at the charge.
     *
     * @param MarketplaceSeller[] $sellers
     */
    public function createCheckoutIntent(
        CreateIntentRequest $request,
        array $sellers,
        string $multiSellerPolicy = self::POLICY_ALL_OR_NOTHING,
        ?string $idempotencyKey = null
    ): CheckoutIntent {
        $body = array_merge($request->toArray(), [
            'sellers' => array_map(static fn (MarketplaceSeller $s): array => $s->toArray(), array_values($sellers)),
            'multiSellerPolicy' => $multiSellerPolicy,
        ]);

        $response = $this->api->request('POST', self::PAY_BASE . '/intents', $body, [
            'stepUp' => true,
            'idempotencyKey' => $idempotencyKey,
        ]);

        $intent = new CheckoutIntent($response);
        // Mirror PaymentClient::createIntent — a non-success body (e.g. a seller
        // ineligible under ALL_OR_NOTHING) must throw, not return an empty form
        // that strands the buyer on a dead pay page with an emptied cart.
        if ($intent->token() === '' && $intent->checkoutFormContent() === '') {
            throw new ApiException(
                $intent->message() !== '' ? $intent->message() : 'Marketplace checkout intent did not return a token.',
                0,
                $intent->code() !== '' ? $intent->code() : null,
                $response
            );
        }

        return $intent;
    }

    /**
     * Direct server-to-server marketplace split charge (no hosted form).
     *
     * ⚠️ PCI: raw PAN — SAQ-D only. Most marketplaces use createCheckoutIntent().
     * Step-up signed. The split legs travel in the payload (sellers[]).
     *
     * @param array<string,mixed> $payload paidPrice, currency, orderId, card{…},
     *   buyer{…}, sellers[]{ subMerchantKey, paidPrice, ... }…
     * @param bool $threeDS true → POST /marketplace/3d; false → /marketplace/2d.
     * @return array<string,mixed>
     */
    public function chargeMarketplaceCard(array $payload, bool $threeDS = false, ?string $idempotencyKey = null): array
    {
        return $this->api->request('POST', self::PAY_BASE . '/' . ($threeDS ? '3d' : '2d'), $payload, [
            'stepUp' => true,
            'idempotencyKey' => $idempotencyKey,
        ]);
    }

    public function createDealer(CreateDealerRequest $request): Dealer
    {
        $response = $this->api->request('POST', self::BASE . '/dealers', $request->toArray());

        return new Dealer(self::dataOf($response));
    }

    /**
     * Bulk-creates sub-dealers (CSV/array import).
     *
     * @param array<int,array<string,mixed>> $dealers
     * @return array<string,mixed>
     */
    public function bulkCreateDealers(array $dealers): array
    {
        return $this->api->request('POST', self::BASE . '/dealers/bulk', ['dealers' => array_values($dealers)]);
    }

    // ── Dealer onboarding lifecycle (merchant-driven) ────────────────────────

    /**
     * Reads a dealer's onboarding/application status.
     *
     * @return array<string,mixed>
     */
    public function dealerApplication(string $dealerId): array
    {
        return $this->api->request('GET', self::BASE . '/dealers/' . rawurlencode($dealerId) . '/application');
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function submitDealerApplication(string $dealerId, array $payload = []): array
    {
        return $this->api->request('POST', self::BASE . '/dealers/' . rawurlencode($dealerId) . '/submit', $payload);
    }

    /**
     * @param array<string,mixed> $payload commission/terms definition.
     * @return array<string,mixed>
     */
    public function setDealerTerms(string $dealerId, array $payload): array
    {
        return $this->api->request('POST', self::BASE . '/dealers/' . rawurlencode($dealerId) . '/terms', $payload);
    }

    /**
     * @param array<string,mixed> $payload signature/contract acceptance.
     * @return array<string,mixed>
     */
    public function signDealerContract(string $dealerId, array $payload = []): array
    {
        return $this->api->request('POST', self::BASE . '/dealers/' . rawurlencode($dealerId) . '/sign', $payload);
    }

    /** @return array<string,mixed> */
    public function activateDealer(string $dealerId): array
    {
        return $this->api->request('POST', self::BASE . '/dealers/' . rawurlencode($dealerId) . '/activate');
    }

    /**
     * Records a KYC outcome for a dealer (when the merchant drives dealer KYC).
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function recordDealerKycResult(string $dealerId, array $payload): array
    {
        return $this->api->request('POST', self::BASE . '/dealers/' . rawurlencode($dealerId) . '/kyc-result', $payload);
    }

    /**
     * @param array{status?:string, search?:string, limit?:int, offset?:int} $filters
     * @return Dealer[]
     */
    public function listDealers(array $filters = []): array
    {
        $query = self::query($filters, ['status', 'search', 'limit', 'offset']);
        $response = $this->api->request('GET', self::BASE . '/dealers', null, ['query' => $query]);

        $rows = isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
        return array_map(static fn ($row): Dealer => new Dealer(is_array($row) ? $row : []), array_values($rows));
    }

    public function getDealer(string $dealerId): Dealer
    {
        $response = $this->api->request('GET', self::BASE . '/dealers/' . rawurlencode($dealerId));

        return new Dealer(self::dataOf($response));
    }

    public function updateDealerStatus(string $dealerId, string $status): Dealer
    {
        $response = $this->api->request('PUT', self::BASE . '/dealers/' . rawurlencode($dealerId) . '/status', ['status' => $status]);

        return new Dealer(self::dataOf($response));
    }

    /** Mints a self-onboarding link for a sub-dealer. */
    public function onboardingToken(string $dealerId): DealerOnboardingLink
    {
        $response = $this->api->request('POST', self::BASE . '/dealers/' . rawurlencode($dealerId) . '/onboarding-token');

        return new DealerOnboardingLink($response);
    }

    /**
     * @param array{status?:string, subDealerId?:string, days?:int, limit?:int} $filters
     * @return array<int,array<string,mixed>>
     */
    public function payoutLedger(array $filters = []): array
    {
        $query = self::query($filters, ['status', 'subDealerId', 'days', 'limit']);
        $response = $this->api->request('GET', self::BASE . '/payout-ledger', null, ['query' => $query]);

        return self::items($response);
    }

    /**
     * @param array{status?:string, subDealerId?:string, limit?:int} $filters
     * @return array<int,array<string,mixed>>
     */
    public function transactions(array $filters = []): array
    {
        $query = self::query($filters, ['status', 'subDealerId', 'limit']);
        $response = $this->api->request('GET', self::BASE . '/transactions', null, ['query' => $query]);

        return self::items($response);
    }

    /**
     * @param array{includeArchived?:bool, limit?:int} $filters
     * @return array<int,array<string,mixed>>
     */
    public function commissionRules(array $filters = []): array
    {
        $query = self::query($filters, ['limit']);
        $response = $this->api->request('GET', self::BASE . '/commission-rules', null, ['query' => $query]);

        return self::items($response);
    }

    /**
     * Creates/updates a commission rule.
     *
     * @param array<string,mixed> $rule
     * @return array<string,mixed>
     */
    public function upsertCommissionRule(array $rule): array
    {
        return $this->api->request('POST', self::BASE . '/commission-rules', $rule);
    }

    /**
     * Dry-run previews a commission rule's effect.
     *
     * @param array<string,mixed> $rule
     * @return array<string,mixed>
     */
    public function previewCommissionRule(array $rule): array
    {
        return $this->api->request('POST', self::BASE . '/commission-rules/preview', $rule);
    }

    /**
     * Resolves the effective commission for a dealer/category at a given MTD volume.
     *
     * @return array<string,mixed>
     */
    public function resolveCommission(string $subDealerId, string $categoryCode = '', ?int $mtdVolumeMinor = null): array
    {
        $query = ['subDealerId' => $subDealerId];
        if ($categoryCode !== '') {
            $query['categoryCode'] = $categoryCode;
        }
        if ($mtdVolumeMinor !== null) {
            $query['mtdVolumeMinor'] = $mtdVolumeMinor;
        }

        return $this->api->request('GET', self::BASE . '/commission/resolve', null, ['query' => $query]);
    }

    // ── Payouts (settlement) ─────────────────────────────────────────────────

    /**
     * Creates a manual payout.
     *
     * @param array<string,mixed> $payload subDealerId, amountMinor, currency, reference…
     * @return array<string,mixed>
     */
    public function createPayout(array $payload): array
    {
        return $this->api->request('POST', self::BASE . '/payouts', $payload);
    }

    /**
     * @param array<string,mixed> $payload paidAt, reference…
     * @return array<string,mixed>
     */
    public function markPayoutPaid(string $payoutId, array $payload = []): array
    {
        return $this->api->request('POST', self::BASE . '/payouts/' . rawurlencode($payoutId) . '/mark-paid', $payload);
    }

    /**
     * @param string[] $payoutIds
     * @return array<string,mixed>
     */
    public function bulkMarkPayoutsPaid(array $payoutIds, array $extra = []): array
    {
        return $this->api->request('POST', self::BASE . '/payouts/bulk-mark-paid', array_merge(['payoutIds' => array_values($payoutIds)], $extra));
    }

    // ── Audit log (append-only, hash-chained) ────────────────────────────────

    /**
     * @param array{limit?:int, type?:string} $filters
     * @return array<int,array<string,mixed>>
     */
    public function auditLog(array $filters = []): array
    {
        $query = self::query($filters, ['limit', 'type']);

        return self::items($this->api->request('GET', self::BASE . '/audit-log', null, ['query' => $query]));
    }

    /**
     * Verifies the audit hash-chain integrity.
     *
     * @return array<string,mixed>
     */
    public function verifyAuditLog(): array
    {
        return $this->api->request('GET', self::BASE . '/audit-log/verify');
    }

    /**
     * @param array<string,mixed> $filters
     * @param string[]            $keys
     * @return array<string,scalar>
     */
    private static function query(array $filters, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $out[$key] = $filters[$key];
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $response
     * @return array<int,array<string,mixed>>
     */
    private static function items(array $response): array
    {
        $rows = $response['items'] ?? ($response['data'] ?? []);
        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    private static function dataOf(array $response): array
    {
        return isset($response['data']) && is_array($response['data']) ? $response['data'] : $response;
    }
}
