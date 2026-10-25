<?php

declare(strict_types=1);

/*
 * Last month's reconciliation with your insurance providers: totals per
 * connection and the CSV file (one line per policy, amounts in minor units).
 * A file holds at most 20000 orders; a longer export continues with
 * nextCursor(). The period is on the order creation time, at most 366 days.
 */

require_once __DIR__ . '/../_common/bootstrap.php';

$insurance = sample_client()->insurance();
$utc = new DateTimeZone('UTC');
$period = [
    'from' => new DateTimeImmutable('first day of last month midnight', $utc),
    'to' => new DateTimeImmutable('first day of this month midnight', $utc),
];

$summary = $insurance->getReconciliationSummary($period);
foreach ($summary->groups() as $group) {
    sample_print([
        'provider' => $group->provider()->name(),
        'currency' => $group->currency(),
        'premiumCollectedBy' => $group->premiumCollectedBy(),
        'orderCount' => $group->totals()->orderCount(),
        'collectedPremiumAmount' => $group->totals()->collectedPremiumAmount(),
        'inForcePolicyCount' => $group->totals()->inForcePolicyCount(),
        'refundOpenOrderCount' => $group->totals()->refundOpenOrderCount(),
    ]);
}

$cursor = null;
for ($part = 1; ; $part++) {
    $file = $insurance->exportReconciliation($period + ['cursor' => $cursor]);
    if ($file->aborted()) {
        throw new RuntimeException('The export was interrupted; request it again.');
    }
    file_put_contents("insurance-reconciliation-{$part}.csv", $file->bytes());
    if (!$file->truncated()) {
        break;
    }
    $cursor = $file->nextCursor();
}
