<?php
declare(strict_types=1);
namespace Lunixi\Sdk\Commerce;

use Lunixi\Sdk\LunixiClient;
use Lunixi\Sdk\Payment\CreateIntentRequest;
use Lunixi\Sdk\Webhook\WebhookEvent;

/** Durable, framework-independent checkout orchestration shared by native adapters. */
final class CheckoutService
{
    private LunixiClient $client;
    private LedgerStore $store;
    private SecretBox $box;

    public function __construct(LunixiClient $client, LedgerStore $store, SecretBox $box)
    {
        $url = parse_url($client->config()->baseUrl());
        if (($url['scheme'] ?? '') !== 'https' || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) {
            throw new \InvalidArgumentException('Commerce gateway credentials require an HTTPS origin.');
        }
        $this->client = $client; $this->store = $store; $this->box = $box;
    }

    /** Reservation commits BEFORE the network call. Uncertain retries reuse its exact key/body. */
    public function start(string $orderKey, OrderSnapshot $order, string $callbackUrl, bool $hosted = false, bool $preauth = false, string $locale = 'en'): CheckoutSession
    {
        $url = parse_url($callbackUrl);
        if (($url['scheme'] ?? '') !== 'https' || !isset($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['fragment'])) {
            throw new \InvalidArgumentException('A merchant HTTPS callback URL is required.');
        }
        $metadata = ['lunixiCommerce' => ['fingerprint' => $order->fingerprint()]];
        if ($hosted) { $metadata['allowedOrigin'] = 'https://pay.lunixi.com'; }
        $request = (new CreateIntentRequest($order->amount(), $order->currency(), $order->orderId()))->withCallbackUrl($callbackUrl)->withMetadata($metadata)->withPreauth($preauth)->withLocale($locale);
        $hash = hash('sha256', json_encode($request->toArray(), JSON_THROW_ON_ERROR));
        $record = $this->store->transaction($orderKey, function () use ($orderKey, $order, $hash): array {
            $r = $this->store->load($orderKey);
            if ($r !== null) {
                if (($r['requestHash'] ?? null) !== $hash) { throw new \RuntimeException('Order or checkout configuration changed; reconcile the existing attempt first.'); }
                return $r;
            }
            $r = ['snapshot' => $order->toArray(), 'requestHash' => $hash, 'key' => 'lunixi-' . bin2hex(random_bytes(24)), 'state' => 'reserved', 'paymentId' => '', 'token' => '', 'createdAt' => time()];
            $this->store->save($orderKey, $r);
            return $r;
        });
        if (($record['capturedAmount'] ?? 0) > 0 || in_array($record['state'], ['paid', 'authorized', 'partially_captured', 'partially_refunded', 'refunded', 'failed'], true)) {
            throw new \RuntimeException('This attempt has concluded; reconcile the order before another checkout.');
        }
        if ($record['paymentId'] !== '' && $record['token'] !== '') {
            if (time() - $record['createdAt'] >= 840) { throw new \RuntimeException('Checkout token expired; reconcile before starting another attempt.'); }
            return new CheckoutSession($record['paymentId'], $this->box->decrypt($record['token']));
        }
        // Opaque reservation binding supports safe webhook recovery after a lost
        // create response. It is stable across retries and never browser input.
        $metadata['lunixiCommerce']['attempt'] = hash('sha256', $record['key']);
        $request->withMetadata($metadata);
        // No automatic key rotation on timeout, 5xx, unknown or malformed response.
        self::assertReplayWindow($record['createdAt'] ?? null);
        $intent = $this->client->payments()->createIntent($request, $record['key']);
        if ($intent->paymentId() === '' || $intent->token() === '') { throw new \RuntimeException('Checkout result is uncertain; retry this reserved attempt.'); }
        $this->store->transaction($orderKey, function () use ($orderKey, $record, $intent): void {
            $current = $this->store->load($orderKey);
            if ($current === null || $current['key'] !== $record['key'] || ($current['paymentId'] !== '' && $current['paymentId'] !== $intent->paymentId())) { throw new \RuntimeException('Concurrent checkout identity mismatch.'); }
            $current['paymentId'] = $intent->paymentId();
            $current['token'] = $this->box->encrypt($intent->token());
            if ($current['state'] === 'reserved') { $current['state'] = 'pending'; }
            $this->store->save($orderKey, $current);
        });
        return new CheckoutSession($intent->paymentId(), $intent->token());
    }

    /** A new attempt is allowed only after an authenticated terminal failure, never on token expiry alone. */
    public function resetFailedAttempt(string $orderKey): void
    {
        $r = $this->store->transaction($orderKey, function () use ($orderKey): array {
            $r = $this->store->load($orderKey);
            if ($r === null || $r['paymentId'] === '') { throw new \RuntimeException('A bound attempt is required.'); }
            return $r;
        });
        $response = $this->client->api()->request('GET', '/api/v1/payments/' . rawurlencode($r['paymentId']));
        $p = $response['data'] ?? $response;
        $split = is_array($p['split'] ?? null) ? $this->client->payments()->getSplitPayment($p['split']['splitPaymentId'])->raw() : null;
        $decision = PaymentEvidence::evaluate(OrderSnapshot::fromArray($r['snapshot']), $r['paymentId'], $response, $split);
        if ($decision->state !== 'failed' || !in_array($decision->gatewayStatus, ['VOIDED', 'EXPIRED'], true) || $decision->capturedAmount !== 0 || $decision->refundedAmount !== 0 || $decision->splitId !== null) { throw new \RuntimeException('The previous payment has not reached a verified terminal failure.'); }
        $this->store->transaction($orderKey, function () use ($orderKey, $r): void {
            $current = $this->store->load($orderKey);
            if ($current === null || $current['key'] !== $r['key'] || ($current['capturedAmount'] ?? 0) > 0 || in_array($current['state'], ['paid', 'partially_refunded', 'refunded'], true)) { throw new \RuntimeException('Attempt changed while checking failure.'); }
            $history = $current['history'] ?? [];
            $history[] = ['paymentId' => $current['paymentId'], 'state' => 'failed', 'createdAt' => $current['createdAt'], 'operations' => $current['operations'] ?? []];
            $current['history'] = $history;
            $current['key'] = 'lunixi-' . bin2hex(random_bytes(24));
            $current['state'] = 'reserved'; $current['paymentId'] = ''; $current['token'] = ''; $current['createdAt'] = time();
            unset($current['chargedAmount'], $current['capturedAmount'], $current['refundedAmount'], $current['splitId'], $current['operations'], $current['gatewayStatus']);
            $this->store->save($orderKey, $current);
        });
    }

    /**
     * Strict authenticated envelope, signed id/type binding, freshness, then durable consumption in reconcile().
     * @param array<string,string> $headers
     */
    public function verifyWebhook(string $body, array $headers, string $secret): WebhookEvent
    {
        if (strlen($body) > 1048576) { throw new \InvalidArgumentException('Webhook body exceeds the limit.'); }
        $event = $this->client->webhooks()->verify($body, $headers, $secret);
        $envelope = $event->envelope();
        $normalized = array_change_key_case($headers, CASE_LOWER);
        if (!is_array($envelope['data'] ?? null) || !is_string($envelope['id'] ?? null) || $envelope['id'] !== ($normalized['x-lunixi-event-id'] ?? null) || !is_string($envelope['type'] ?? null) || $envelope['type'] === '') {
            throw new \RuntimeException('A signed webhook envelope with matching event identity is required.');
        }
        return $event;
    }

    /**
     * Native admin controllers MUST perform their own capability + CSRF checks.
     * operationId is a durable platform refund/capture/void identifier, not a
     * fresh random value per HTTP request. Lost responses retry this same ID.
     * The mutation response never changes an order: authenticated GET does.
     */
    public function mutate(string $orderKey, string $operationId, string $action, ?int $amount, callable $apply): PaymentDecision
    {
        if (!in_array($action, ['refund', 'capture', 'void'], true) || trim($operationId) === '' || strlen($operationId) > 191 || ($action !== 'void' && ($amount === null || $amount < 1)) || ($action === 'void' && $amount !== null)) {
            throw new \InvalidArgumentException('A stable operation ID and valid mutation amount/action are required.');
        }
        if ($amount !== null) { Money::integer($amount); }
        $r = $this->store->transaction($orderKey, function () use ($orderKey): array {
            $r = $this->store->load($orderKey);
            if ($r === null || $r['paymentId'] === '') { throw new \RuntimeException('A bound payment is required.'); }
            return $r;
        });
        $opId = hash('sha256', $operationId);
        $existing = $r['operations'][$opId] ?? null;
        if ($existing !== null && ($existing['action'] !== $action || $existing['amount'] !== $amount)) { throw new \RuntimeException('Operation ID was already used for a different mutation.'); }
        if ($existing === null) {
            $response = $this->client->api()->request('GET', '/api/v1/payments/' . rawurlencode($r['paymentId']));
            $p = $response['data'] ?? $response;
            $split = is_array($p['split'] ?? null) ? $this->client->payments()->getSplitPayment($p['split']['splitPaymentId'])->raw() : null;
            $e = PaymentEvidence::evaluate(OrderSnapshot::fromArray($r['snapshot']), $r['paymentId'], $response, $split);
            if (($action === 'refund' && (!in_array($e->state, ['paid', 'partially_captured', 'partially_refunded'], true) || $amount > $e->capturedAmount - $e->refundedAmount)) || ($action === 'capture' && (!in_array($e->state, ['authorized', 'partially_captured'], true) || $amount > $e->chargedAmount - $e->capturedAmount || $e->splitId !== null)) || ($action === 'void' && ($e->state !== 'authorized' || $e->splitId !== null))) {
                throw new \RuntimeException('Authenticated payment state/balance does not permit this mutation.');
            }
            $existing = $this->store->transaction($orderKey, function () use ($orderKey, $r, $opId, $action, $amount, $e): array {
                $current = $this->store->load($orderKey);
                if ($current === null || $current['key'] !== $r['key']) { throw new \RuntimeException('Attempt changed before the mutation.'); }
                $op = $current['operations'][$opId] ?? null;
                if ($op !== null) {
                    if ($op['action'] !== $action || $op['amount'] !== $amount) { throw new \RuntimeException('Concurrent operation identity mismatch.'); }
                    return $op;
                }
                foreach ($current['operations'] ?? [] as $other) {
                    if (($other['state'] ?? '') !== 'confirmed') { throw new \RuntimeException('Reconcile or retry the unresolved operation before starting another mutation.'); }
                }
                $op = ['action' => $action, 'amount' => $amount, 'key' => 'lunixi-op-' . bin2hex(random_bytes(24)), 'splitId' => $e->splitId, 'initialCaptured' => $e->capturedAmount, 'initialRefunded' => $e->refundedAmount, 'state' => 'pending', 'createdAt' => time()];
                $current['operations'][$opId] = $op;
                $this->store->save($orderKey, $current);
                return $op;
            });
        }
        try {
            if ($existing['state'] !== 'confirmed') {
                self::assertReplayWindow($existing['createdAt'] ?? null);
                if ($action === 'refund') {
                    if ($amount === null) { throw new \LogicException('Refund amount is required.'); }
                    if ($existing['splitId'] !== null) { $this->client->payments()->refundSplitPayment($existing['splitId'], $amount, 'Commerce refund', $existing['key']); }
                    else { $this->client->payments()->refund($r['paymentId'], $amount, 'Commerce refund', $existing['key']); }
                } elseif ($action === 'capture') { $this->client->payments()->capture($r['paymentId'], $amount, $existing['key']); }
                else { $this->client->payments()->void($r['paymentId'], $existing['key']); }
            }
            return $this->reconcile($orderKey, $apply);
        } catch (\Throwable $error) {
            $this->store->transaction($orderKey, function () use ($orderKey, $r, $opId): void {
                $current = $this->store->load($orderKey);
                if ($current !== null && $current['key'] === $r['key'] && isset($current['operations'][$opId]) && $current['operations'][$opId]['state'] !== 'confirmed') {
                    $current['operations'][$opId]['state'] = 'uncertain';
                    $this->store->save($orderKey, $current);
                }
            });
            throw $error;
        }
    }

    /**
     * Safe native admin status; a 2xx mutation response alone never means confirmed.
     * @return array<string,mixed>|null
     */
    public function operationStatus(string $orderKey, string $operationId): ?array
    {
        return $this->store->transaction($orderKey, function () use ($orderKey, $operationId): ?array {
            $r = $this->store->load($orderKey);
            $op = $r['operations'][hash('sha256', $operationId)] ?? null;
            if ($op === null) { return null; }
            unset($op['key']);
            return $op;
        });
    }

    /**
     * The gateway HTTP replay cache lasts 24h; stop earlier and reconcile instead.
     * @param mixed $createdAt Persisted records may predate the timestamp field.
     */
    private static function assertReplayWindow($createdAt): void
    {
        if (!is_int($createdAt) || $createdAt > time() || time() - $createdAt >= 23 * 3600) {
            throw new \RuntimeException('Financial replay window expired or unknown; use authenticated reconciliation and operator review, never a new operation key.');
        }
    }

    /**
     * $apply changes the native order on this store's transaction/connection.
     * It MUST be idempotent by paymentId and must not send emails inside the
     * transaction: use the platform outbox/post-commit notification mechanism.
     * Callback requests may invoke reconcile, but their submitted status is ignored.
     */
    public function reconcile(string $orderKey, callable $apply, ?WebhookEvent $event = null): PaymentDecision
    {
        $r = $this->store->transaction($orderKey, function () use ($orderKey): array {
            $r = $this->store->load($orderKey);
            if ($r === null) { throw new \RuntimeException('Checkout attempt is not reserved; retry delivery.'); }
            return $r;
        });
        $candidate = $r['paymentId'];
        if ($event !== null) {
            $id = self::eventPaymentId($event);
            if ($candidate !== '' && $id !== $candidate) { throw new \RuntimeException('Webhook payment does not match the persisted attempt.'); }
            $candidate = $id;
        }
        if ($candidate === '') { throw new \RuntimeException('An authenticated webhook or bound payment is required for recovery.'); }
        $response = $this->client->api()->request('GET', '/api/v1/payments/' . rawurlencode($candidate));
        $p = $response['data'] ?? $response;
        $order = OrderSnapshot::fromArray($r['snapshot']);
        if ($r['paymentId'] === '') {
            $metadata = $p['metadata'] ?? null;
            if (!is_array($metadata) && is_string($p['metadataJson'] ?? null)) { $metadata = json_decode($p['metadataJson'], true); }
            if ($event === null || $event->rawBodyHash() === null || strpos($event->type(), 'payment.') !== 0 || !is_array($metadata) || ($metadata['lunixiCommerce']['attempt'] ?? null) !== hash('sha256', $r['key']) || ($metadata['lunixiCommerce']['fingerprint'] ?? null) !== $order->fingerprint()) {
                throw new \RuntimeException('Unbound payment does not prove the original reserved attempt.');
            }
        }
        $split = null;
        if (is_array($p['split'] ?? null) && is_string($p['split']['splitPaymentId'] ?? null)) {
            $split = $this->client->payments()->getSplitPayment($p['split']['splitPaymentId'])->raw();
        }
        $decision = PaymentEvidence::evaluate($order, $candidate, $response, $split);
        return $this->store->transaction($orderKey, function () use ($orderKey, $r, $decision, $event, $apply): PaymentDecision {
            $current = $this->store->load($orderKey);
            if ($current === null || $current['key'] !== $r['key'] || ($current['paymentId'] !== '' && $current['paymentId'] !== $decision->paymentId)) { throw new \RuntimeException('Checkout changed during reconciliation.'); }
            $current['paymentId'] = $decision->paymentId;
            if ($event !== null) {
                $hash = $event->rawBodyHash();
                if ($hash === null) { throw new \RuntimeException('A verified raw-body event is required.'); }
                if (!$this->store->claimEvent($r['snapshot']['merchantId'], $event->id(), $hash)) { return self::storedDecision($current); }
            }
            // Delayed observations cannot downgrade already captured/refunded orders.
            $rank = ['reserved' => 0, 'pending' => 0, 'review' => 0, 'failed' => 1, 'authorized' => 2, 'partially_captured' => 3, 'paid' => 4, 'partially_refunded' => 5, 'refunded' => 6];
            $capturedBefore = $current['capturedAmount'] ?? 0;
            $releasedAuthorization = $current['state'] === 'authorized' && $decision->state === 'failed' && in_array($decision->gatewayStatus, ['VOIDED', 'EXPIRED'], true);
            if ($decision->capturedAmount < $capturedBefore || $decision->refundedAmount < ($current['refundedAmount'] ?? 0) || (($rank[$decision->state] ?? 0) < ($rank[$current['state']] ?? 0) && !$releasedAuthorization && $decision->capturedAmount <= $capturedBefore)) { return self::storedDecision($current); }
            if ($current['state'] !== $decision->state || ($current['refundedAmount'] ?? 0) !== $decision->refundedAmount || $capturedBefore !== $decision->capturedAmount) { $apply($decision); }
            foreach ($current['operations'] ?? [] as $id => $op) {
                if (self::operationConfirmed($op, $decision)) { $current['operations'][$id]['state'] = 'confirmed'; }
            }
            $current['state'] = $decision->state;
            $current['refundedAmount'] = $decision->refundedAmount;
            $current['chargedAmount'] = $decision->chargedAmount;
            $current['capturedAmount'] = $decision->capturedAmount;
            $current['gatewayStatus'] = $decision->gatewayStatus;
            $current['splitId'] = $decision->splitId;
            $this->store->save($orderKey, $current);
            return $decision;
        });
    }

    /** Candidate lookup only: callers must verify the event and reconcile before changing an order. */
    public static function eventPaymentId(WebhookEvent $event): string
    {
        $data = $event->data(); $identifiers = [];
        foreach (['paymentId', 'intentId', 'paymentIntentId'] as $field) { if (array_key_exists($field, $data)) { $identifiers[] = $data[$field]; } }
        if ($identifiers === [] && array_key_exists('id', $data)) { $identifiers[] = $data['id']; }
        foreach ($identifiers as $id) {
            if (!is_string($id) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,199}$/D', $id)) { throw new \RuntimeException('Webhook payment identifier is invalid.'); }
        }
        if ($identifiers === [] || count(array_unique($identifiers)) !== 1) { throw new \RuntimeException('Webhook payment identifiers are missing or inconsistent.'); }
        return $identifiers[0];
    }

    /** @param array<string,mixed> $record */
    private static function storedDecision(array $record): PaymentDecision
    {
        return new PaymentDecision($record['state'], $record['paymentId'], $record['chargedAmount'] ?? $record['snapshot']['amount'], $record['refundedAmount'] ?? 0, $record['splitId'] ?? null, $record['capturedAmount'] ?? 0, $record['gatewayStatus'] ?? '');
    }

    /** @param array<string,mixed> $operation */
    private static function operationConfirmed(array $operation, PaymentDecision $decision): bool
    {
        if ($operation['action'] === 'refund') { return $decision->refundedAmount >= $operation['initialRefunded'] + $operation['amount']; }
        if ($operation['action'] === 'capture') { return $decision->capturedAmount >= $operation['initialCaptured'] + $operation['amount']; }
        return $decision->state === 'failed' && $decision->capturedAmount === 0;
    }
}
