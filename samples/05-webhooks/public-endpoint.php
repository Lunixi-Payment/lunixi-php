<?php

declare(strict_types=1);

use Lunixi\Sdk\Exception\WebhookVerificationException;

require_once __DIR__ . '/../_common/bootstrap.php';

// Minimal framework-free endpoint skeleton. In production, replace
// sample_webhook_already_processed/sample_mark_webhook_processed with a durable
// database table keyed by event id. Delivery is at-least-once.

$rawBody = file_get_contents('php://input');
$rawBody = $rawBody === false ? '' : $rawBody;

try {
    $event = sample_client()->webhooks()->verify(
        $rawBody,
        getallheaders() ?: [],
        sample_required_env('LUNIXI_WEBHOOK_SECRET')
    );
} catch (WebhookVerificationException $e) {
    http_response_code(400);
    echo 'invalid signature';
    return;
}

if (sample_webhook_already_processed($event->id())) {
    http_response_code(200);
    echo 'duplicate';
    return;
}

switch ($event->type()) {
    case 'payment.captured':
    case 'payment.succeeded':
    case 'payment.completed':
        // Mark the order paid from the authenticated event payload.
        // Never finalize solely from a browser redirect.
        break;

    case 'payment.failed':
        // Mark the active attempt failed, then allow a new checkout attempt.
        break;

    case 'payment.refunded':
    case 'payment.partially_refunded':
        // Reconcile refund state from the event payload.
        break;
}

sample_mark_webhook_processed($event->id());

http_response_code(200);
echo 'ok';

function sample_webhook_already_processed(string $eventId): bool
{
    return false;
}

function sample_mark_webhook_processed(string $eventId): void
{
    // Persist event id after successful handling.
}
