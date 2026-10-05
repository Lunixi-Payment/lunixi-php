<?php

declare(strict_types=1);

/*
 * Lists links newest first, with filters. `state`, `usage` and `amountMode`
 * accept several values. Paging is cursor-based: pass nextCursor() back as
 * `cursor`, or let iterate() follow the cursors for you.
 */

use Lunixi\Sdk\Payment\PaymentLinkState;

require_once __DIR__ . '/../_common/bootstrap.php';

$links = sample_client()->paymentLinks();

$page = $links->list([
    'limit' => 20,
    'state' => [PaymentLinkState::ACTIVE, PaymentLinkState::PAUSED],
    'query' => sample_env('LUNIXI_PAYMENT_LINK_QUERY'),
]);
sample_print(['count' => $page->count(), 'hasMore' => $page->hasMore(), 'nextCursor' => $page->nextCursor()]);

$collectedMinor = 0;
foreach ($links->iterate(['state' => PaymentLinkState::COMPLETED, 'limit' => 100]) as $link) {
    $collectedMinor += $link->collectedAmountMinor();
}
sample_print(['completedLinksCollectedMinor' => $collectedMinor]);
