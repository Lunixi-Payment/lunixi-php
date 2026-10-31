<?php

declare(strict_types=1);

namespace app\components;

use Lunixi\Sdk\Commerce\LedgerStore;

final class NativeLedger implements LedgerStore
{
    private ?string $active = null;
    public function __construct(private Store $db)
    {
    }
    public function transaction(string $orderKey, callable $operation)
    {
        $this->assertIdle($orderKey);
        $tx = $this->db->connection->beginTransaction();
        try {
            $this->db->execute('INSERT INTO lunixi_attempts(order_key,payload) VALUES (?,NULL) ON CONFLICT(order_key) DO UPDATE SET order_key=excluded.order_key', [$orderKey]);
            $this->active = $orderKey;
            $result = $operation();
            if ($tx->getLevel() !== 1 || !$tx->getIsActive() || !$this->db->pdo()->inTransaction()) {
                throw new \RuntimeException('Native transaction boundary changed.');
            }
            $tx->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($tx->getIsActive()) {
                $tx->rollBack();
            } throw $e;
        } finally {
            $this->active = null;
        }
    }
    public function load(string $orderKey): ?array
    {
        $this->guard($orderKey);
        $row = $this->db->one('SELECT payload FROM lunixi_attempts WHERE order_key=?', [$orderKey]);
        return $row === null || $row['payload'] === null ? null : json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
    }
    public function save(string $orderKey, array $record): void
    {
        $this->guard($orderKey);
        $this->db->execute('UPDATE lunixi_attempts SET payload=? WHERE order_key=?', [json_encode($record, JSON_THROW_ON_ERROR), $orderKey]);
    }
    public function claimEvent(string $merchantId, string $eventId, string $payloadHash): bool
    {
        if ($this->active === null || !preg_match('/^[a-f0-9]{64}$/D', $payloadHash)) {
            throw new \RuntimeException('Event claim requires native transaction.');
        }
        $key = hash('sha256', $merchantId . "\0" . $eventId);
        if ($this->db->execute('INSERT OR IGNORE INTO lunixi_events(event_key,payload_hash) VALUES (?,?)', [$key, $payloadHash]) === 1) {
            return true;
        }
        $row = $this->db->one('SELECT payload_hash FROM lunixi_events WHERE event_key=?', [$key]);
        if ($row === null || !hash_equals($row['payload_hash'], $payloadHash)) {
            throw new \RuntimeException('Event identity collision.');
        }
        return false;
    }
    private function assertIdle(string $orderKey): void
    {
        if ($this->active !== null || $this->db->connection->getTransaction() !== null || $this->db->pdo()->inTransaction() || !preg_match('/^yii:order:[1-9][0-9]*$/D', $orderKey)) {
            throw new \RuntimeException('Standalone serialized native transaction required.');
        }
    }
    private function guard(string $key): void
    {
        if ($this->active !== $key || $this->db->connection->getTransaction() === null) {
            throw new \RuntimeException('Serialized native ledger transaction required.');
        }
    }
}
