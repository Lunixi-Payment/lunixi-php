<?php

declare(strict_types=1);

namespace App\Commands;

use App\Payments\Sql;
use CodeIgniter\CLI\{BaseCommand,CLI};

final class LunixiReconcile extends BaseCommand
{
    protected $group = 'Lunixi';
    protected $name = 'lunixi:reconcile';
    protected $description = 'Authenticated reconciliation; no browser success is trusted.';
    public function run(array $params): int
    {
        $service = \Config\Services::lunixi();
        $errors = 0;
        foreach (Sql::all(Sql::connection(), 'SELECT id FROM orders WHERE state NOT IN (?,?)', ['cancelled','refunded']) as $row) {
            try {
                $service->reconcile((int) $row['id']);
            } catch (\Throwable $error) {
                ++$errors;
            }
        }
        CLI::write(json_encode(['errors' => $errors], JSON_THROW_ON_ERROR));
        return $errors === 0 ? 0 : 1;
    }
}
