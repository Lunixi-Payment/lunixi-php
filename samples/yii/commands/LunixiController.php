<?php

declare(strict_types=1);

namespace app\commands;

use app\components\Runtime;
use app\components\{Checkout,Schema,Settings,Store};

final class LunixiController extends \yii\console\Controller
{
    public function actionSetup(): int
    {
        $settings = Settings::fromEnvironment();
        $store = new Store(Runtime::db());
        Schema::install($store);
        foreach (['alice','bob'] as $n => $name) {
            $password = getenv('DEMO_' . strtoupper($name) . '_PASSWORD');
            if ($password === false || strlen($password) < 16) {
                throw new \RuntimeException('Supply separate DEMO_ALICE_PASSWORD and DEMO_BOB_PASSWORD of at least 16 characters.');
            }
            $email = $name . '@example.test';
            if ($store->one('SELECT id FROM users WHERE email=?', [$email]) !== null) {
                continue;
            }
            $id = $n + 1;
            $store->execute('INSERT INTO users(id,email,password_hash,auth_key) VALUES (?,?,?,?)', [$id,$email,password_hash($password, PASSWORD_DEFAULT),bin2hex(random_bytes(32))]);
            // Demo order of a fictional customer (Schema::DEMO_ORDER_DETAILS).
            Schema::insertOrder($store, ['id' => $id, 'user_id' => $id, 'merchant_id' => $settings->merchantId, 'environment' => $settings->environment, 'amount' => 1990, 'currency' => 'TRY', 'mode' => $settings->mode, 'preauth' => (int) $settings->preauth] + Schema::DEMO_ORDER_DETAILS);
        }
        $this->stdout("Lunixi example schema/accounts ready; no secret was printed.\n");
        return 0;
    }
    public function actionReconcile(): int
    {
        $store = new Store(Runtime::db());
        $service = new Checkout($store, Settings::fromEnvironment());
        $errors = 0;
        foreach ($store->all('SELECT id FROM orders WHERE state NOT IN (?,?)', ['cancelled','refunded']) as $row) {
            try {
                $service->reconcile((int) $row['id']);
            } catch (\Throwable $e) {
                ++$errors;
            }
        }
        $this->stdout(json_encode(['errors' => $errors], JSON_THROW_ON_ERROR) . "\n");
        return $errors === 0 ? 0 : 1;
    }
}
