<?php

declare(strict_types=1);

namespace App\Commands;

use App\Payments\Sql;
use CodeIgniter\CLI\{BaseCommand, CLI};
use App\Payments\{Schema, Settings};

final class LunixiSetup extends BaseCommand
{
    protected $group = 'Lunixi';
    protected $name = 'lunixi:setup';
    protected $description = 'Install standalone example tables and optional explicit demo users/orders.';
    public function run(array $params): void
    {
        $settings = Settings::fromEnvironment();
        $db = Sql::connection();
        Schema::install($db);
        foreach (['alice', 'bob'] as $n => $name) {
            $password = getenv('DEMO_' . strtoupper($name) . '_PASSWORD');
            if ($password === false || strlen($password) < 16) {
                throw new \RuntimeException('Supply separate DEMO_ALICE_PASSWORD and DEMO_BOB_PASSWORD values of at least 16 characters.');
            }
            $email = $name . '@example.test';
            if (Sql::one($db, 'SELECT id FROM users WHERE email=?', [$email]) !== null) {
                continue;
            }
            $id = $n + 1;
            $db->query('INSERT INTO users(id,email,password_hash) VALUES (?,?,?)', [$id, $email, password_hash($password, PASSWORD_DEFAULT)]);
            // Demo order of a fictional customer (Schema::DEMO_ORDER_DETAILS).
            Schema::insertOrder($db, ['id' => $id, 'user_id' => $id, 'merchant_id' => $settings->merchantId, 'environment' => $settings->environment, 'amount' => 1990, 'currency' => 'TRY', 'mode' => $settings->mode, 'preauth' => (int) $settings->preauth] + Schema::DEMO_ORDER_DETAILS);
        }
        CLI::write('Lunixi example schema/demo accounts ready; no key/password was printed.');
    }
}
