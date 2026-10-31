<?php

declare(strict_types=1);

namespace App\Payments;

use CodeIgniter\Database\SQLite3\Connection;

final class Schema
{
    public static function install(Connection $db): void
    {
        if ($db->DBDriver !== 'SQLite3' || $db->transDepth !== 0) {
            throw new \RuntimeException('Native SQLite installation requires no outer transaction.');
        }
        foreach (
            [
            'CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY,email TEXT NOT NULL UNIQUE,password_hash TEXT NOT NULL)',
            'CREATE TABLE IF NOT EXISTS orders(id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,merchant_id TEXT NOT NULL,environment TEXT NOT NULL,amount INTEGER NOT NULL CHECK(amount>0),currency TEXT NOT NULL,mode TEXT NOT NULL,preauth INTEGER NOT NULL DEFAULT 0,state TEXT NOT NULL DEFAULT \'pending\',payment_id TEXT NOT NULL DEFAULT \'\',charged INTEGER NOT NULL DEFAULT 0,captured INTEGER NOT NULL DEFAULT 0,refunded INTEGER NOT NULL DEFAULT 0,FOREIGN KEY(user_id) REFERENCES users(id))',
            'CREATE TABLE IF NOT EXISTS lunixi_attempts(order_key TEXT PRIMARY KEY,payload TEXT NULL)',
            'CREATE TABLE IF NOT EXISTS lunixi_events(event_key TEXT PRIMARY KEY,payload_hash TEXT NOT NULL)',
            'CREATE TABLE IF NOT EXISTS payment_outbox(id TEXT PRIMARY KEY,order_id INTEGER NOT NULL,payload TEXT NOT NULL,state TEXT NOT NULL DEFAULT \'ready\')',
            ] as $sql
        ) {
            $db->query($sql);
        }
    }
}
