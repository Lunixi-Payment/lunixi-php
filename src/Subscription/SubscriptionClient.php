<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Subscription;

use Lunixi\Sdk\ApiClient;

/**
 * Server-side subscription operations against the gateway subscription REST
 * surface (`/api/v1/subscriptions/*`). Unlike payments, these calls are bearer
 * authenticated only — no Ed25519 step-up. Create/change-plan SHOULD carry a
 * stable Idempotency-Key (creation requires one).
 *
 * The subscription entity ops live here; related resources hang off accessors:
 *   $sub  = $client->create($req);
 *   $plan = $client->plans()->get($planId);
 *   $card = $client->cards()->add($cardReq);
 *   $inv  = $client->invoices()->list(['subscriptionId' => $sub->id()]);
 */
final class SubscriptionClient
{
    private const BASE = '/api/v1/subscriptions/subscriptions';

    private ApiClient $api;

    private ?PlanClient $plans = null;
    private ?CustomerClient $customers = null;
    private ?CardClient $cards = null;
    private ?InvoiceClient $invoices = null;
    private ?PortalClient $portal = null;
    private ?ProductClient $products = null;
    private ?FeatureClient $features = null;
    private ?AddonClient $addons = null;
    private ?UsageClient $usage = null;

    public function __construct(ApiClient $api)
    {
        $this->api = $api;
    }

    /** Creates a subscription. The request carries the (required) idempotency key. */
    public function create(CreateSubscriptionRequest $request): Subscription
    {
        $response = $this->api->request('POST', self::BASE, $request->toArray(), [
            'idempotencyKey' => $request->idempotencyKey(),
        ]);

        return new Subscription(Helpers::dataOf($response));
    }

    public function get(string $subscriptionId): Subscription
    {
        $response = $this->api->request('GET', self::BASE . '/' . rawurlencode($subscriptionId));

        return new Subscription(Helpers::dataOf($response));
    }

    /**
     * @param array{customerId?:string, status?:string, pageSize?:int, pageToken?:string} $filters
     * @return CursorList<Subscription>
     */
    public function list(array $filters = []): CursorList
    {
        $response = $this->api->request('GET', self::BASE, null, ['query' => Helpers::query($filters)]);

        return new CursorList($response, static fn (array $row): Subscription => new Subscription($row));
    }

    /**
     * Cancels a subscription. Pass atPeriodEnd=true to schedule the cancel for
     * the current period end instead of immediately.
     */
    public function cancel(string $subscriptionId, bool $atPeriodEnd = false, ?string $reason = null): Subscription
    {
        $body = ['cancelAtPeriodEnd' => $atPeriodEnd];
        if ($reason !== null && $reason !== '') {
            $body['reason'] = $reason;
        }
        $response = $this->api->request('POST', self::BASE . '/' . rawurlencode($subscriptionId) . '/cancel', $body);

        return new Subscription(Helpers::dataOf($response));
    }

    /** Pauses a subscription. Optional ISO-8601 resume instant. */
    public function pause(string $subscriptionId, ?string $resumeAt = null): Subscription
    {
        $body = ($resumeAt !== null && $resumeAt !== '') ? ['resumeAt' => $resumeAt] : null;
        $response = $this->api->request('POST', self::BASE . '/' . rawurlencode($subscriptionId) . '/pause', $body);

        return new Subscription(Helpers::dataOf($response));
    }

    public function resume(string $subscriptionId): Subscription
    {
        $response = $this->api->request('POST', self::BASE . '/' . rawurlencode($subscriptionId) . '/resume');

        return new Subscription(Helpers::dataOf($response));
    }

    public function changePlan(string $subscriptionId, ChangePlanRequest $request): Subscription
    {
        $response = $this->api->request(
            'POST',
            self::BASE . '/' . rawurlencode($subscriptionId) . '/change-plan',
            $request->toArray(),
            ['idempotencyKey' => $request->idempotencyKey()]
        );

        return new Subscription(Helpers::dataOf($response));
    }

    public function plans(): PlanClient
    {
        return $this->plans ?? $this->plans = new PlanClient($this->api);
    }

    public function customers(): CustomerClient
    {
        return $this->customers ?? $this->customers = new CustomerClient($this->api);
    }

    public function cards(): CardClient
    {
        return $this->cards ?? $this->cards = new CardClient($this->api);
    }

    public function invoices(): InvoiceClient
    {
        return $this->invoices ?? $this->invoices = new InvoiceClient($this->api);
    }

    public function portal(): PortalClient
    {
        return $this->portal ?? $this->portal = new PortalClient($this->api);
    }

    public function products(): ProductClient
    {
        return $this->products ?? $this->products = new ProductClient($this->api);
    }

    public function features(): FeatureClient
    {
        return $this->features ?? $this->features = new FeatureClient($this->api);
    }

    public function addons(): AddonClient
    {
        return $this->addons ?? $this->addons = new AddonClient($this->api);
    }

    public function usage(): UsageClient
    {
        return $this->usage ?? $this->usage = new UsageClient($this->api);
    }

    /**
     * Audit trail for a subscription.
     *
     * @param array{limit?:int, pageToken?:string} $filters
     * @return array<string,mixed>
     */
    public function auditLogs(string $subscriptionId, array $filters = []): array
    {
        return $this->api->request('GET', self::BASE . '/' . rawurlencode($subscriptionId) . '/audit-logs', null, ['query' => Helpers::query($filters)]);
    }
}
