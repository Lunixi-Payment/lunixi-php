<?php

declare(strict_types=1);

// CLI-only offline cache fixture. Never install a test route or create a real payment.
$database = getenv('LUNIXI_DATABASE');
if (
    PHP_SAPI !== 'cli'
    || getenv('LUNIXI_NATIVE_HTTP_CACHE_FIXTURE') !== '1'
    || getenv('LUNIXI_ENVIRONMENT') !== 'TEST'
    || getenv('LUNIXI_MERCHANT_ID') !== 'native-http-fixture'
    || !is_string($database)
    || basename($database) !== 'sample.sqlite'
    || !str_starts_with(basename(dirname($database)), 'lunixi-native-http-')
) {
    throw new RuntimeException('Disposable native HTTP fixture required.');
}

define('FCPATH', dirname(__DIR__) . '/public/');
define('ENVIRONMENT', 'development');
require dirname(__DIR__) . '/app/Config/Paths.php';
$paths = new Config\Paths();
require $paths->systemDirectory . '/Boot.php';
CodeIgniter\Boot::bootConsole($paths);
require __DIR__ . '/GatewayFixture.php';

$db = App\Payments\Sql::connection();
if ((int) App\Payments\Sql::one($db, 'SELECT COUNT(*) AS total FROM lunixi_attempts')['total'] !== 0) {
    throw new RuntimeException('Expected an unused disposable checkout cache.');
}
$db->query('UPDATE orders SET mode=? WHERE id=1', ['hosted']);
$db->query(
    'INSERT INTO orders(id,user_id,merchant_id,environment,amount,currency,mode,preauth) VALUES (?,?,?,?,?,?,?,?)',
    [3, 1, 'native-http-fixture', 'TEST', 1990, 'TRY', 'embedded', 0]
);
$checkout = new App\Payments\Checkout($db, App\Payments\Settings::fromEnvironment(), new GatewayFixture());
$checkout->start(1, 1);
$checkout->start(3, 1);
echo "Offline native checkout cache ready.\n";
