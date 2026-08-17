<?php

declare(strict_types=1);

require_once __DIR__ . '/../_common/bootstrap.php';

// Usage:
//   cat payload.json | LUNIXI_HEADER_EVENT_ID=evt_... \
//     LUNIXI_HEADER_EVENT_TYPE=payment.captured \
//     LUNIXI_HEADER_TIMESTAMP=2026-08-16T12:00:00Z \
//     LUNIXI_HEADER_SIGNATURE=sha256=... \
//     php samples/05-webhooks/verify-from-stdin.php

$rawBody = stream_get_contents(STDIN);
if ($rawBody === false || trim($rawBody) === '') {
    throw new RuntimeException('Provide the raw webhook JSON body on STDIN.');
}

$headers = [
    'x-lunixi-event-id' => sample_required_env('LUNIXI_HEADER_EVENT_ID'),
    'x-lunixi-event-type' => sample_required_env('LUNIXI_HEADER_EVENT_TYPE'),
    'x-lunixi-signature-timestamp' => sample_required_env('LUNIXI_HEADER_TIMESTAMP'),
    'x-lunixi-signature' => sample_required_env('LUNIXI_HEADER_SIGNATURE'),
];

$event = sample_client()->webhooks()->verify(
    $rawBody,
    $headers,
    sample_required_env('LUNIXI_WEBHOOK_SECRET')
);

sample_print([
    'id' => $event->id(),
    'type' => $event->type(),
    'timestamp' => $event->timestamp(),
    'data' => $event->data(),
]);
