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
            $db->query('INSERT INTO orders(id,user_id,merchant_id,environment,amount,currency,mode,preauth) VALUES (?,?,?,?,?,?,?,?)', [$id, $id, $settings->merchantId, $settings->environment, 1990, 'TRY', $settings->mode, (int) $settings->preauth]);
        }
        CLI::write('Lunixi example schema/demo accounts ready; no key/password was printed.');
    }
}
