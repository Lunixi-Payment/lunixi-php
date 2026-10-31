<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
try {
    $config = json_decode(file_get_contents($argv[1]), true, 32, JSON_THROW_ON_ERROR);
    if (!preg_match('/^lunixi_[a-zA-Z0-9_]+$/D', $config['database']) || !preg_match('/^lx_test_[a-f0-9]{8}_$/D', $argv[2])) { throw new RuntimeException('Disposable fixture required.'); }
    $db = new PDO('mysql:host=' . $config['host'] . ';port=' . $config['port'] . ';dbname=' . $config['database'] . ';charset=utf8mb4', $config['username'], $config['password']);
    $store = new \Lunixi\Sdk\Commerce\PdoLedgerStore($db, $argv[2]);
    for ($i = 0; $i < 20; ++$i) {
        $store->transaction('existing-order', function () use ($store, $db, $argv, $i): void {
            $row = $store->load('existing-order');
            usleep(1000);
            $store->save('existing-order', ['counter' => $row['counter'] + 1]);
            if ($store->claimEvent('merchant', 'event-' . $i, hash('sha256', 'event-' . $i))) {
                $db->exec('UPDATE ' . $argv[2] . 'native_order SET applied = applied + 1 WHERE id = 1');
            }
        });
    }
    echo 'ok';
} catch (Throwable $error) { fwrite(STDERR, get_class($error) . ': fixture operation failed'); exit(1); }
