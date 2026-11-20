<?php

declare(strict_types=1);

namespace App\Payments;

use CodeIgniter\Database\SQLite3\Connection;
use Lunixi\Sdk\LunixiClient;
use Lunixi\Sdk\Commerce\{CheckoutService, CheckoutSession, OrderSnapshot, PaymentDecision, SecretBox, Money};
use Lunixi\Sdk\Http\HttpClientInterface;
use Lunixi\Sdk\Payment\{Address, BasketItem, Buyer};

/** Application ownership and persistence glue; all payment proof/retry rules are shared SDK code. */
final class Checkout
{
    private NativeLedger $ledger;
    private CheckoutService $service;
    private SecretBox $box;
    public function __construct(private Connection $db, public readonly Settings $settings, ?HttpClientInterface $http = null)
    {
        $this->ledger = new NativeLedger($db);
        $this->box = new SecretBox($settings->encryptionKey);
        $client = LunixiClient::create(['baseUrl' => 'https://api-gateway.lunixi.com', 'keyId' => $settings->keyId, 'privateKey' => $settings->privateKey], $http);
        $this->service = new CheckoutService($client, $this->ledger, $this->box);
    }
    /** @return array<string,mixed> */
    public function order(int $id, ?int $owner = null): array
    {
        $row = Sql::one($this->db, 'SELECT * FROM orders WHERE id=?', [$id]);
        if ($row === null || ($owner !== null && (int) $row['user_id'] !== $owner)) {
            throw new \DomainException('Order unavailable.');
        }
        return $row;
    }
    /** `$buyerIp`: the client IP of the checkout request (IncomingRequest::getIPAddress()); empty = REMOTE_ADDR. */
    public function start(int $id, int $owner, string $buyerIp = ''): CheckoutSession
    {
        $order = $this->order($id, $owner);
        $this->scope($order);
        if ($order['state'] !== 'pending') {
            throw new \DomainException('Order is not payable.');
        }
        $buyerIp = $buyerIp !== '' ? $buyerIp : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $snapshot = $this->withOrderDetails($this->snapshot($order), $order, $buyerIp);
        return $this->service->start(self::key($id), $snapshot, $this->settings->baseUrl . '/payments/' . $id . '/return', $order['mode'] === 'hosted', (bool) $order['preauth'], 'en');
    }
    /** @param array<string,string> $headers */
    public function webhook(string $body, array $headers): void
    {
        $event = $this->service->verifyWebhook($body, $headers, $this->settings->webhookSecret);
        if (($event->envelope()['environment'] ?? null) !== $this->settings->environment) {
            throw new \DomainException('Webhook environment mismatch.');
        }
        $paymentId = CheckoutService::eventPaymentId($event);
        $row = Sql::one($this->db, 'SELECT id FROM orders WHERE payment_id=?', [$paymentId]);
        $candidate = (string) ($event->data()['orderId'] ?? '');
        if ($row !== null) {
            $id = (int) $row['id'];
        } elseif (preg_match('/^ci:order:([1-9][0-9]*)$/D', $candidate, $m)) {
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
        $this->db->query('UPDATE orders SET state=?,payment_id=?,charged=?,captured=?,refunded=? WHERE id=?', [$decision->state, $decision->paymentId, $decision->chargedAmount, $decision->capturedAmount, $decision->refundedAmount, $id]);
        if ($decision->isPaid()) {
            $idempotent = hash('sha256', $decision->paymentId . ':paid');
            $this->db->query('INSERT OR IGNORE INTO payment_outbox(id,order_id,payload) VALUES (?,?,?)', [$idempotent, $id, $this->box->encrypt(json_encode(['type' => 'order.paid', 'orderId' => $id, 'paymentId' => $decision->paymentId], JSON_THROW_ON_ERROR))]);
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
        return new OrderSnapshot($order['merchant_id'], self::key((int) $order['id']), Money::fromStorage($order['amount']), $order['currency']);
    }
    /**
     * Adds the order details every payment requires from the order record
     * (Schema::ORDER_DETAIL_COLUMNS): the buyer, the billing address, the
     * basket and, for a PHYSICAL order, the shipping address (the billing
     * address when no shipping address is stored). They are not part of the
     * snapshot identity (toArray()/fingerprint()), so apply() keeps comparing
     * the reserved snapshot as before. `ip` is the buyer's browser IP from the
     * checkout request. The example order is one line for the order total; with
     * order lines, map each line so the `price` values add up to the amount. A
     * missing value stops the checkout with a ConfigurationException before
     * anything is reserved or sent.
     *
     * @param array<string,mixed> $order
     */
    private function withOrderDetails(OrderSnapshot $snapshot, array $order, string $buyerIp): OrderSnapshot
    {
        $text = static fn (string $column): string => trim((string) ($order[$column] ?? ''));
        $fullName = trim($text('buyer_name') . ' ' . $text('buyer_surname'));
        $billing = new Address([
            'contactName' => $text('billing_contact') !== '' ? $text('billing_contact') : $fullName,
            'address' => $text('billing_address'),
            'city' => $text('billing_city'),
            'country' => $text('billing_country'),
            'zipCode' => $text('billing_zip'),
        ]);
        $snapshot = $snapshot
            ->withBuyer(new Buyer([
                'name' => $text('buyer_name'),
                'surname' => $text('buyer_surname'),
                'identityNumber' => $text('buyer_identity_number'),
                'email' => $text('buyer_email'),
                'gsmNumber' => $text('buyer_gsm'),
                'city' => $text('billing_city'),
                'country' => $text('billing_country'),
                'zipCode' => $text('billing_zip'),
                'ip' => $buyerIp,
            ]))
            ->withBillingAddress($billing);
        $itemType = $text('item_type') === BasketItem::TYPE_PHYSICAL ? BasketItem::TYPE_PHYSICAL : BasketItem::TYPE_VIRTUAL;
        $snapshot = $snapshot->withBasketItems([new BasketItem([
            'id' => (string) $order['id'],
            'price' => $snapshot->amount(),
            'name' => 'Order #' . $order['id'],
            'category1' => 'General',
            'itemType' => $itemType,
        ])]);
        if ($itemType !== BasketItem::TYPE_PHYSICAL) {
            return $snapshot;
        }

        return $snapshot->withShippingAddress($text('shipping_address') === '' ? $billing : new Address([
            'contactName' => $text('shipping_contact') !== '' ? $text('shipping_contact') : $fullName,
            'address' => $text('shipping_address'),
            'city' => $text('shipping_city'),
            'country' => $text('shipping_country'),
            'zipCode' => $text('shipping_zip'),
        ]));
    }
    public static function key(int $id): string
    {
        if ($id < 1) {
            throw new \InvalidArgumentException('Positive order ID required.');
        }
        return 'ci:order:' . $id;
    }
}
