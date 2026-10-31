<?php
declare(strict_types=1);
namespace Lunixi\Sdk\Commerce;

/** SQLite/MySQL durable ledger. Reuse the platform's PDO connection for atomic order updates. */
final class PdoLedgerStore implements LedgerStore
{
    private \PDO $db;
    private string $prefix;
    private string $driver;
    private bool $active = false;
    private string $activeKey = '';
    public function __construct(\PDO $db, string $prefix = 'lunixi_')
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,45}$/D', $prefix)) { throw new \InvalidArgumentException('Invalid table prefix.'); }
        $driver = $db->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, ['mysql', 'sqlite'], true)) { throw new \InvalidArgumentException('Ledger supports MySQL and SQLite.'); }
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db = $db; $this->prefix = $prefix; $this->driver = $driver;
        if ($driver === 'sqlite') { $db->exec('PRAGMA busy_timeout = 10000'); }
    }
    /** Run during installation/migration, never from a webhook. DDL is not transactional on MySQL. */
    public function install(): void
    {
        $suffix = $this->driver === 'mysql' ? ' ENGINE=InnoDB' : '';
        $keyType = $this->driver === 'mysql' ? 'VARBINARY(191)' : 'VARCHAR(191)';
        $payloadType = $this->driver === 'mysql' ? 'LONGTEXT' : 'TEXT';
        $this->db->exec('CREATE TABLE IF NOT EXISTS ' . $this->prefix . 'attempts (order_key ' . $keyType . ' PRIMARY KEY, payload ' . $payloadType . ' NULL)' . $suffix);
        $this->db->exec('CREATE TABLE IF NOT EXISTS ' . $this->prefix . 'events (event_hash CHAR(64) PRIMARY KEY, payload_hash CHAR(64) NOT NULL, received_at BIGINT NOT NULL)' . $suffix);
    }
    public function transaction(string $orderKey, callable $operation)
    {
        if ($orderKey === '' || strlen($orderKey) > 191 || $this->active || $this->db->inTransaction()) { throw new \RuntimeException('A standalone ledger transaction and valid order key are required.'); }
        $this->active = true;
        $this->activeKey = $orderKey;
        try {
            if ($this->driver === 'sqlite') { $this->db->exec('BEGIN IMMEDIATE'); } else { $this->db->beginTransaction(); }
            $sql = $this->driver === 'mysql' ? 'INSERT IGNORE INTO ' : 'INSERT OR IGNORE INTO ';
            $this->db->prepare($sql . $this->prefix . 'attempts (order_key, payload) VALUES (?, NULL)')->execute([$orderKey]);
            if ($this->driver === 'mysql') {
                $lock = $this->db->prepare('SELECT order_key FROM ' . $this->prefix . 'attempts WHERE order_key = ? FOR UPDATE');
                $lock->execute([$orderKey]); $lock->fetchColumn();
            }
            $result = $operation();
            if ($this->driver === 'sqlite') { $this->db->exec('COMMIT'); } else { $this->db->commit(); }
            return $result;
        } catch (\Throwable $e) {
            try { if ($this->driver === 'sqlite') { $this->db->exec('ROLLBACK'); } else { $this->db->rollBack(); } } catch (\Throwable $ignored) { /* Preserve original failure. */ }
            throw $e;
        } finally { $this->active = false; $this->activeKey = ''; }
    }
    public function load(string $orderKey): ?array
    {
        $this->assertTransaction();
        if ($orderKey !== $this->activeKey) { throw new \RuntimeException('Order access is outside its lock.'); }
        $q = $this->db->prepare('SELECT payload FROM ' . $this->prefix . 'attempts WHERE order_key = ?'); $q->execute([$orderKey]);
        $json = $q->fetchColumn();
        return is_string($json) ? json_decode($json, true, 64, JSON_THROW_ON_ERROR) : null;
    }
    public function save(string $orderKey, array $record): void
    {
        $this->assertTransaction();
        if ($orderKey !== $this->activeKey) { throw new \RuntimeException('Order access is outside its lock.'); }
        $this->db->prepare('UPDATE ' . $this->prefix . 'attempts SET payload = ? WHERE order_key = ?')->execute([json_encode($record, JSON_THROW_ON_ERROR), $orderKey]);
    }
    public function claimEvent(string $merchantId, string $eventId, string $payloadHash): bool
    {
        $this->assertTransaction();
        if ($merchantId === '' || $eventId === '' || !preg_match('/^[a-f0-9]{64}$/D', $payloadHash)) { throw new \InvalidArgumentException('Event identity and SHA-256 payload hash are required.'); }
        $sql = $this->driver === 'mysql' ? 'INSERT IGNORE INTO ' : 'INSERT OR IGNORE INTO ';
        $eventHash = hash('sha256', $merchantId . "\0" . $eventId);
        $q = $this->db->prepare($sql . $this->prefix . 'events (event_hash, payload_hash, received_at) VALUES (?, ?, ?)');
        $q->execute([$eventHash, $payloadHash, time()]);
        if ($q->rowCount() === 1) { return true; }
        $q = $this->db->prepare('SELECT payload_hash FROM ' . $this->prefix . 'events WHERE event_hash = ?'); $q->execute([$eventHash]);
        $existing = $q->fetchColumn();
        if (!is_string($existing) || !hash_equals($existing, $payloadHash)) { throw new \RuntimeException('Event ID collision with different authenticated content.'); }
        return false;
    }
    private function assertTransaction(): void
    {
        if (!$this->active) { throw new \RuntimeException('Ledger access must be inside its order transaction.'); }
    }
}
