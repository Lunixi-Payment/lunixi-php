<?php

declare(strict_types=1);

namespace app\components;

use yii\db\Connection;

final class Store
{
    public function __construct(public readonly Connection $connection)
    {
        $connection->open();
        if ($connection->driverName !== 'sqlite') {
            throw new \InvalidArgumentException('This runnable example supports native SQLite PDO only.');
        }
        $connection->createCommand('PRAGMA busy_timeout=10000')->execute();
        $connection->createCommand('PRAGMA foreign_keys=ON')->execute();
    }
    public function pdo(): \PDO
    {
        $pdo = $this->connection->pdo;
        if (!$pdo instanceof \PDO) {
            throw new \RuntimeException('Native PDO connection is closed.');
        }
        return $pdo;
    }
    /** @param list<mixed> $params */
    private function command(string $sql, array $params): \yii\db\Command
    {
        $bound = [];
        foreach ($params as $i => $value) {
            $bound[$i + 1] = $value;
        }
        return $this->connection->createCommand($sql, $bound);
    }
    /**
     * @param list<mixed> $params
     * @return array<string,mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->command($sql, $params)->queryOne();
        return $row === false ? null : $row;
    }
    /**
     * @param list<mixed> $params
     * @return list<array<string,mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        return array_values($this->command($sql, $params)->queryAll());
    }
    /** @param list<mixed> $params */
    public function execute(string $sql, array $params = []): int
    {
        return $this->command($sql, $params)->execute();
    }
}
