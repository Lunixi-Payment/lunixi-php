<?php

declare(strict_types=1);

namespace app\components;

use Lunixi\Sdk\LunixiClient;
use Lunixi\Sdk\Commerce\{CheckoutService, CheckoutSession, OrderSnapshot, PaymentDecision, SecretBox, Money};
use Lunixi\Sdk\Http\HttpClientInterface;

/** Application ownership and persistence glue; all payment proof/retry rules are shared SDK code. */
final class Checkout
{
    private NativeLedger $ledger;
    private CheckoutService $service;
    private SecretBox $box;
    public function __construct(private Store $db, public readonly Settings $settings, ?HttpClientInterface $http = null)
    {
        $this->ledger = new NativeLedger($db);
        $this->box = new SecretBox($settings->encryptionKey);
        $client = LunixiClient::create(['baseUrl' => 'https://api-gateway.lunixi.com', 'keyId' => $settings->keyId, 'privateKey' => $settings->privateKey], $http);
        $this->service = new CheckoutService($client, $this->ledger, $this->box);
    }
    /** @return array<string,mixed> */
    public function order(int $id, ?int $owner = null): array
    {
        $row = $this->db->one('SELECT * FROM orders WHERE id=?', [$id]);
        if ($row === null || ($owner !== null && (int) $row['user_id'] !== $owner)) {
            throw new \DomainException('Order unavailable.');
        }
        return $row;
    }
    public function start(int $id, int $owner): CheckoutSession
    {
        $order = $this->order($id, $owner);
        $this->scope($order);
        if ($order['state'] !== 'pending') {
            throw new \DomainException('Order is not payable.');
        }
        return $this->service->start(self::key($id), $this->snapshot($order), $this->settings->baseUrl . '/payments/' . $id . '/return', $order['mode'] === 'hosted', (bool) $order['preauth'], 'en');
    }
    /** @param array<string,string> $headers */
    public function webhook(string $body, array $headers): void
    {
        $event = $this->service->verifyWebhook($body, $headers, $this->settings->webhookSecret);
        if (($event->envelope()['environment'] ?? null) !== $this->settings->environment) {
            throw new \DomainException('Webhook environment mismatch.');
        }
        $paymentId = CheckoutService::eventPaymentId($event);
        $row = $this->db->one('SELECT id FROM orders WHERE payment_id=?', [$paymentId]);
        $candidate = (string) ($event->data()['orderId'] ?? '');
        if ($row !== null) {
            $id = (int) $row['id'];
        } elseif (preg_match('/^yii:order:([1-9][0-9]*)$/D', $candidate, $m)) {
            $id = (int) $m[1];
        } else {
            throw new \DomainException('No reserved order matches payment.');
        }
        $this->scope($this->order($id));
        $this->service->reconcile(self::key($id), fn (PaymentDecision $decision) => $this->apply($id, $decision), $event);
    }
    public function reconcile(int $id): void
    {
        $this->scope($this->order($id));
        $this->service->reconcile(self::key($id), fn (PaymentDecision $decision) => $this->apply($id, $decision));
    }
    private function apply(int $id, PaymentDecision $decision): void
    {
        $order = $this->order($id);
        $this->scope($order);
        $record = $this->ledger->load(self::key($id));
        if ($record === null || $this->snapshot($order)->toArray() !== $record['snapshot'] || ($order['payment_id'] !== '' && $order['payment_id'] !== $decision->paymentId)) {
            throw new \RuntimeException('Native order changed after reservation.');
        }
        if ($order['state'] === 'cancelled') {
            throw new \RuntimeException('Native cancelled order requires review.');
        }
        $this->db->execute('UPDATE orders SET state=?,payment_id=?,charged=?,captured=?,refunded=? WHERE id=?', [$decision->state, $decision->paymentId, $decision->chargedAmount, $decision->capturedAmount, $decision->refundedAmount, $id]);
        if ($decision->isPaid()) {
            $idempotent = hash('sha256', $decision->paymentId . ':paid');
            $this->db->execute('INSERT OR IGNORE INTO payment_outbox(id,order_id,payload) VALUES (?,?,?)', [$idempotent, $id, $this->box->encrypt(json_encode(['type' => 'order.paid', 'orderId' => $id, 'paymentId' => $decision->paymentId], JSON_THROW_ON_ERROR))]);
        }
    }
    /** @param array<string,mixed> $order */
    private function scope(array $order): void
    {
        if ($order['merchant_id'] !== $this->settings->merchantId || $order['environment'] !== $this->settings->environment || !in_array($order['mode'], ['hosted', 'embedded'], true)) {
            throw new \RuntimeException('Order merchant/environment scope changed.');
        }
    }
    /** @param array<string,mixed> $order */
    private function snapshot(array $order): OrderSnapshot
    {
        return new OrderSnapshot($order['merchant_id'], self::key((int) $order['id']), Money::integer($order['amount']), $order['currency']);
    }
    public static function key(int $id): string
    {
        if ($id < 1) {
            throw new \InvalidArgumentException('Positive order ID required.');
        }
        return 'yii:order:' . $id;
    }
}
