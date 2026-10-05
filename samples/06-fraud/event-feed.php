<?php

/**
 * Feeding the event backbone — the history that counting rules read.
 *
 * A single decision call only knows the transaction in front of it. Rules that
 * count over time ("more than 5 distinct payees today") need this feed.
 *
 * These two routes are signed PER REQUEST (Ed25519 step-up), so the signing key
 * must be configured — a bearer token alone is not enough.
 */

declare(strict_types=1);

require_once __DIR__ . '/../_common/bootstrap.php';

$events = sample_client()->fraud()->events;

// ── Single event ──────────────────────────────────────────────────────────────
// Retrying with the same eventId is safe: the second call is deduplicated.
$single = $events->record([
    'eventId' => 'evt_' . bin2hex(random_bytes(8)),
    'eventType' => 'transfer_completed',
    'subjectType' => 'transaction',
    'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
    'customerId' => 'cus_88',
    // transfer_* and withdrawal_* events REQUIRE a counterparty.
    'counterparty' => 'TR330006100519786457841326',
    'amountMinor' => 250000,
    'currency' => 'TRY',
    'direction' => 'outbound',
    'ip' => '203.0.113.7',
    // Sector-average rules read the merchant MCC from here.
    'metadata' => ['mcc' => '7995'],
]);

sample_print([
    'eventId' => $single->eventId(),
    'rowId' => $single->rowId(),
    'deduplicated' => $single->deduplicated(),
    'matchedFlowCount' => $single->matchedFlowCount(),
]);

// ── Batch ─────────────────────────────────────────────────────────────────────
// Up to 500 events per call. Above that the gateway rejects the WHOLE batch.
$batchEvents = [];
for ($i = 0; $i < 3; $i++) {
    $batchEvents[] = [
        'eventId' => 'evt_' . bin2hex(random_bytes(8)),
        'eventType' => 'payment_completed',
        'subjectType' => 'transaction',
        'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
        'customerId' => 'cus_88',
        'amountMinor' => 12900 + $i,
        'currency' => 'TRY',
    ];
}

$batch = $events->recordBatch($batchEvents);

sample_print([
    'total' => $batch->total(),
    'inserted' => $batch->inserted(),
    'deduplicated' => $batch->deduplicated(),
    'failed' => $batch->failed(),
]);

/**
 * 🔴 A BATCH ANSWERS 200 EVEN WHEN SOME EVENTS WERE REJECTED. Reading only the
 *    HTTP status loses those events silently — always walk the failures.
 */
foreach ($batch->failures() as $failure) {
    sample_print([
        'rejected' => $failure->eventId(),
        'reason' => $failure->error(),
    ]);
}
