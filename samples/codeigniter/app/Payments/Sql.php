<?php

declare(strict_types=1);

namespace App\Payments;

use CodeIgniter\Database\SQLite3\{Connection,Result};

final class Sql
{
    public static function connection(): Connection
    {
        $db = db_connect();
        if (!$db instanceof Connection) {
            throw new \RuntimeException('The sample requires native SQLite3.');
        }
        return $db;
    }
    /**
     * @param list<mixed> $params
     * @return array<string,mixed>|null
     */
    public static function one(Connection $db, string $sql, array $params = []): ?array
    {
        $result = $db->query($sql, $params);
        if (!$result instanceof Result) {
            throw new \RuntimeException('Expected a native SQL result.');
        }
        return $result->getRowArray();
    }
    /**
     * @param list<mixed> $params
     * @return list<array<string,mixed>>
     */
    public static function all(Connection $db, string $sql, array $params = []): array
    {
        $result = $db->query($sql, $params);
        if (!$result instanceof Result) {
            throw new \RuntimeException('Expected native SQL rows.');
        }
        return $result->getResultArray();
    }
}
