<?php

declare(strict_types=1);

namespace App\Payments;

use CodeIgniter\Database\SQLite3\Connection;
use Lunixi\Sdk\Commerce\LedgerStore;

/** SQLite-backed native CI4 transactions; order writes use this exact connection. */
final class NativeLedger implements LedgerStore
{
    private ?string $active = null;
    public function __construct(private Connection $db)
    {
        if ($db->DBDriver !== 'SQLite3') {
            throw new \InvalidArgumentException('This runnable sample supports native SQLite3 only.');
        }
        $db->transException(true);
    }
    public function transaction(string $orderKey, callable $operation)
    {
        $this->assertIdle($orderKey);
        if (!$this->db->transBegin()) {
            throw new \RuntimeException('Cannot reserve native transaction.');
        }
        try {
            // First statement takes SQLite's write lock before reading; no deferred read-to-write upgrade.
            $this->db->query('INSERT INTO lunixi_attempts(order_key,payload) VALUES (?,NULL) ON CONFLICT(order_key) DO UPDATE SET order_key=excluded.order_key', [$orderKey]);
            $this->active = $orderKey;
            $result = $operation();
            if ($this->db->transDepth !== 1 || !$this->db->transStatus() || !$this->db->transCommit()) {
                throw new \RuntimeException('Native transaction boundary changed.');
            }
            return $result;
        } catch (\Throwable $e) {
            if ($this->db->transDepth > 0) {
                $this->db->transRollback();
            }
            // CI strict transaction status survives rollback; reset only after our rollback completes.
            if ($this->db->transDepth === 0) {
                $this->db->resetTransStatus();
            }
            throw $e;
        } finally {
            $this->active = null;
        }
    }
    public function load(string $orderKey): ?array
    {
        $this->guard($orderKey);
        $row = Sql::one($this->db, 'SELECT payload FROM lunixi_attempts WHERE order_key=?', [$orderKey]);
        return $row === null || $row['payload'] === null ? null : json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
    }
    public function save(string $orderKey, array $record): void
    {
        $this->guard($orderKey);
        $this->db->query('UPDATE lunixi_attempts SET payload=? WHERE order_key=?', [json_encode($record, JSON_THROW_ON_ERROR), $orderKey]);
    }
    public function claimEvent(string $merchantId, string $eventId, string $payloadHash): bool
    {
        if ($this->active === null || !preg_match('/^[a-f0-9]{64}$/D', $payloadHash)) {
            throw new \RuntimeException('Event claim requires the order transaction.');
        }
        $key = hash('sha256', $merchantId . "\0" . $eventId);
        $this->db->query('INSERT OR IGNORE INTO lunixi_events(event_key,payload_hash) VALUES (?,?)', [$key, $payloadHash]);
        if ($this->db->affectedRows() === 1) {
            return true;
        }
        $row = Sql::one($this->db, 'SELECT payload_hash FROM lunixi_events WHERE event_key=?', [$key]);
        if ($row === null || !hash_equals($row['payload_hash'], $payloadHash)) {
            throw new \RuntimeException('Event identity collision.');
        }
        return false;
    }
    private function assertIdle(string $orderKey): void
    {
        if ($this->active !== null || $this->db->transDepth !== 0 || !preg_match('/^ci:order:[1-9][0-9]*$/D', $orderKey)) {
            throw new \RuntimeException('A standalone serialized order transaction is required.');
        }
    }
    private function guard(string $key): void
    {
        if ($this->active !== $key || $this->db->transDepth !== 1) {
            throw new \RuntimeException('Serialized ledger transaction required.');
        }
    }
}
