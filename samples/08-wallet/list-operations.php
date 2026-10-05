<?php

declare(strict_types=1);

/*
 * An end user's operations, newest first, then one operation with its status
 * history and receipt. Paging is cursor-based: iterate() follows the cursors.
 * Needs `wallet:operation:read`.
 */

require_once __DIR__ . '/../_common/bootstrap.php';

$operations = sample_client()->wallet()->operations;
$endUserId = sample_required_env('LUNIXI_WALLET_END_USER_ID');

$page = $operations->list(['endUserId' => $endUserId, 'limit' => 20]);
sample_print(['count' => $page->count(), 'hasMore' => $page->hasMore(), 'nextCursor' => $page->nextCursor()]);

$completed = 0;
foreach ($operations->iterate(['endUserId' => $endUserId, 'status' => 'COMPLETED', 'limit' => 100]) as $operation) {
    $completed++;
}
sample_print(['completedOperations' => $completed]);

$first = $page->items()[0] ?? null;
if ($first !== null) {
    sample_print($operations->get((string) $first['id']));
    sample_print($operations->getReceipt((string) $first['id']));
}
