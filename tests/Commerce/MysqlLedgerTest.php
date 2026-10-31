<?php
declare(strict_types=1);
namespace Lunixi\Sdk\Tests\Commerce;

use Lunixi\Sdk\Commerce\PdoLedgerStore;
use PHPUnit\Framework\TestCase;

/** Never loads application credentials: an explicitly disposable database file is required. */
final class MysqlLedgerTest extends TestCase
{
    private \PDO $db;
    private string $prefix;
    protected function setUp(): void
    {
        $file = getenv('LUNIXI_TEST_DB_FILE');
        if (!$file || !is_file($file)) { self::markTestSkipped('Set LUNIXI_TEST_DB_FILE to a disposable lunixi_ MySQL database JSON file.'); }
        $config = json_decode(file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
        if (!preg_match('/^lunixi_[a-zA-Z0-9_]+$/D', $config['database'])) { throw new \RuntimeException('Disposable fixture required.'); }
        $this->db = new \PDO('mysql:host=' . $config['host'] . ';port=' . $config['port'] . ';dbname=' . $config['database'] . ';charset=utf8mb4', $config['username'], $config['password']);
        $this->prefix = 'lx_test_' . bin2hex(random_bytes(4)) . '_';
        (new PdoLedgerStore($this->db, $this->prefix))->install();
        $this->db->exec('CREATE TABLE ' . $this->prefix . 'native_order (id INT PRIMARY KEY, applied INT NOT NULL) ENGINE=InnoDB');
        $this->db->exec('INSERT INTO ' . $this->prefix . 'native_order VALUES (1,0)');
    }
    protected function tearDown(): void
    {
        if (isset($this->db, $this->prefix)) { foreach (['events', 'attempts', 'native_order'] as $table) { $this->db->exec('DROP TABLE IF EXISTS ' . $this->prefix . $table); } }
    }
    public function testExistingReservationSerializesThreeProcessesAndAppliesEachEventOnce(): void
    {
        $store = new PdoLedgerStore($this->db, $this->prefix);
        $store->transaction('existing-order', fn () => $store->save('existing-order', ['counter' => 0]));
        $processes = [];
        try {
            for ($i = 0; $i < 3; ++$i) {
                $process = proc_open([PHP_BINARY, __DIR__ . '/mysql-worker.php', (string) getenv('LUNIXI_TEST_DB_FILE'), $this->prefix], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process); $processes[] = [$process, $pipes];
            }
            foreach ($processes as [$process, $pipes]) {
                stream_set_timeout($pipes[1], 30); stream_set_timeout($pipes[2], 30);
                self::assertSame('ok', stream_get_contents($pipes[1])); self::assertSame('', stream_get_contents($pipes[2]));
                fclose($pipes[1]); fclose($pipes[2]); self::assertSame(0, proc_close($process));
            }
            self::assertSame(60, $store->transaction('existing-order', fn () => $store->load('existing-order'))['counter']);
            self::assertSame(20, (int) $this->db->query('SELECT applied FROM ' . $this->prefix . 'native_order')->fetchColumn());
            self::assertSame(20, (int) $this->db->query('SELECT COUNT(*) FROM ' . $this->prefix . 'events')->fetchColumn());
        } finally { foreach ($processes as [$process, $pipes]) { if (is_resource($process)) { proc_terminate($process); proc_close($process); } } }
    }
    public function testNativeWriteAndInboxRollbackTogether(): void
    {
        $store = new PdoLedgerStore($this->db, $this->prefix);
        try {
            $store->transaction('order', function () use ($store): void {
                $store->save('order', ['paid' => true]);
                $store->claimEvent('merchant', 'event', hash('sha256', 'raw'));
                $this->db->exec('UPDATE ' . $this->prefix . 'native_order SET applied = 1');
                throw new \RuntimeException('Synthetic application failure');
            });
            self::fail('Expected rollback.');
        } catch (\RuntimeException $expected) {}
        self::assertSame(0, (int) $this->db->query('SELECT applied FROM ' . $this->prefix . 'native_order')->fetchColumn());
        self::assertTrue($store->transaction('order', fn () => $store->claimEvent('merchant', 'event', hash('sha256', 'raw'))));
        self::assertFalse($store->transaction('order', fn () => $store->claimEvent('merchant', 'event', hash('sha256', 'raw'))));
    }
}
