<?php

declare(strict_types=1);

namespace app\components;

final class Schema
{
    public static function install(Store $db): void
    {
        if ($db->connection->getTransaction() !== null) {
            throw new \RuntimeException('Install outside financial transactions.');
        }
        foreach (
            [
            'CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY,email TEXT NOT NULL UNIQUE,password_hash TEXT NOT NULL,auth_key TEXT NOT NULL)',
            'CREATE TABLE IF NOT EXISTS login_limits(id TEXT PRIMARY KEY,window INTEGER NOT NULL,attempts INTEGER NOT NULL)',
            'CREATE TABLE IF NOT EXISTS orders(id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,merchant_id TEXT NOT NULL,environment TEXT NOT NULL,amount INTEGER NOT NULL CHECK(amount>0),currency TEXT NOT NULL,mode TEXT NOT NULL,preauth INTEGER NOT NULL DEFAULT 0,state TEXT NOT NULL DEFAULT \'pending\',payment_id TEXT NOT NULL DEFAULT \'\',charged INTEGER NOT NULL DEFAULT 0,captured INTEGER NOT NULL DEFAULT 0,refunded INTEGER NOT NULL DEFAULT 0,FOREIGN KEY(user_id) REFERENCES users(id))',
            'CREATE TABLE IF NOT EXISTS lunixi_attempts(order_key TEXT PRIMARY KEY,payload TEXT NULL)',
            'CREATE TABLE IF NOT EXISTS lunixi_events(event_key TEXT PRIMARY KEY,payload_hash TEXT NOT NULL)',
            'CREATE TABLE IF NOT EXISTS payment_outbox(id TEXT PRIMARY KEY,order_id INTEGER NOT NULL,payload TEXT NOT NULL,state TEXT NOT NULL DEFAULT \'ready\')',
            ] as $sql
        ) {
            $db->execute($sql);
        }
    }
}
