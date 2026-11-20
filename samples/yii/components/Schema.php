<?php

declare(strict_types=1);

namespace app\components;

final class Schema
{
    /**
     * Order columns the checkout sends as the order details every payment
     * requires (Checkout::withOrderDetails()): the buyer, the billing address,
     * the shipping address of a PHYSICAL order and the basket line type.
     */
    public const ORDER_DETAIL_COLUMNS = [
        'buyer_name', 'buyer_surname', 'buyer_identity_number', 'buyer_email', 'buyer_gsm',
        'billing_contact', 'billing_address', 'billing_city', 'billing_country', 'billing_zip',
        'shipping_contact', 'shipping_address', 'shipping_city', 'shipping_country', 'shipping_zip',
        'item_type',
    ];

    /**
     * Fictional customer of the demo orders (setup command and the example
     * tests): a placeholder identity number and example.com contact data, no
     * real person. The buyer IP is not stored; it comes from the checkout request.
     */
    public const DEMO_ORDER_DETAILS = [
        'buyer_name' => 'Ada', 'buyer_surname' => 'Yilmaz', 'buyer_identity_number' => '11111111111',
        'buyer_email' => 'ada@example.com', 'buyer_gsm' => '+905350000000',
        'billing_contact' => 'Ada Yilmaz', 'billing_address' => 'Example Street 1', 'billing_city' => 'Istanbul',
        'billing_country' => 'Turkey', 'billing_zip' => '34000',
        'shipping_contact' => 'Ada Yilmaz', 'shipping_address' => 'Example Street 1', 'shipping_city' => 'Istanbul',
        'shipping_country' => 'Turkey', 'shipping_zip' => '34000',
        'item_type' => 'PHYSICAL',
    ];

    private const ORDER_COLUMNS = ['id', 'user_id', 'merchant_id', 'environment', 'amount', 'currency', 'mode', 'preauth'];

    public static function install(Store $db): void
    {
        if ($db->connection->getTransaction() !== null) {
            throw new \RuntimeException('Install outside financial transactions.');
        }
        foreach (
            [
            'CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY,email TEXT NOT NULL UNIQUE,password_hash TEXT NOT NULL,auth_key TEXT NOT NULL)',
            'CREATE TABLE IF NOT EXISTS login_limits(id TEXT PRIMARY KEY,window INTEGER NOT NULL,attempts INTEGER NOT NULL)',
            'CREATE TABLE IF NOT EXISTS orders(id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,merchant_id TEXT NOT NULL,environment TEXT NOT NULL,amount INTEGER NOT NULL CHECK(amount>0),currency TEXT NOT NULL,mode TEXT NOT NULL,preauth INTEGER NOT NULL DEFAULT 0,state TEXT NOT NULL DEFAULT \'pending\',payment_id TEXT NOT NULL DEFAULT \'\',charged INTEGER NOT NULL DEFAULT 0,captured INTEGER NOT NULL DEFAULT 0,refunded INTEGER NOT NULL DEFAULT 0,'
                . 'buyer_name TEXT NOT NULL,buyer_surname TEXT NOT NULL,buyer_identity_number TEXT NOT NULL,buyer_email TEXT NOT NULL,buyer_gsm TEXT NOT NULL,'
                . 'billing_contact TEXT NOT NULL DEFAULT \'\',billing_address TEXT NOT NULL,billing_city TEXT NOT NULL,billing_country TEXT NOT NULL,billing_zip TEXT NOT NULL,'
                . 'shipping_contact TEXT NOT NULL DEFAULT \'\',shipping_address TEXT NOT NULL DEFAULT \'\',shipping_city TEXT NOT NULL DEFAULT \'\',shipping_country TEXT NOT NULL DEFAULT \'\',shipping_zip TEXT NOT NULL DEFAULT \'\','
                . 'item_type TEXT NOT NULL DEFAULT \'VIRTUAL\' CHECK(item_type IN (\'PHYSICAL\',\'VIRTUAL\')),'
                . 'FOREIGN KEY(user_id) REFERENCES users(id))',
            'CREATE TABLE IF NOT EXISTS lunixi_attempts(order_key TEXT PRIMARY KEY,payload TEXT NULL)',
            'CREATE TABLE IF NOT EXISTS lunixi_events(event_key TEXT PRIMARY KEY,payload_hash TEXT NOT NULL)',
            'CREATE TABLE IF NOT EXISTS payment_outbox(id TEXT PRIMARY KEY,order_id INTEGER NOT NULL,payload TEXT NOT NULL,state TEXT NOT NULL DEFAULT \'ready\')',
            ] as $sql
        ) {
            $db->execute($sql);
        }
    }

    /**
     * Inserts an order row; the column names come from this class only.
     *
     * @param array<string,int|string> $order id, user_id, merchant_id, environment, amount, currency, mode, preauth and the ORDER_DETAIL_COLUMNS.
     */
    public static function insertOrder(Store $db, array $order): void
    {
        $unknown = array_diff(array_keys($order), [...self::ORDER_COLUMNS, ...self::ORDER_DETAIL_COLUMNS]);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unknown order column: ' . implode(', ', $unknown));
        }
        $sql = 'INSERT INTO orders(' . implode(',', array_keys($order)) . ') VALUES (' . implode(',', array_fill(0, count($order), '?')) . ')';
        $db->execute($sql, array_values($order));
    }
}
