<?php

declare(strict_types=1);

/*
 * Webhook endpoint for insurance events (subscribe an INSURANCE_SERVICE
 * endpoint to `insurance.*`). The event is verified first and deduplicated by
 * its id: delivery is at-least-once and the id is the same on every
 * redelivery. Decide on the order from the webhook; API reads only show the
 * current state.
 */

use Lunixi\Sdk\Exception\WebhookVerificationException;
use Lunixi\Sdk\Insurance\InsuranceEvents;
use Lunixi\Sdk\Insurance\InsuranceLeadWebhookData;
use Lunixi\Sdk\Insurance\InsuranceWebhookData;

require_once __DIR__ . '/../_common/bootstrap.php';

$rawBody = file_get_contents('php://input');
$rawBody = $rawBody === false ? '' : $rawBody;

try {
    $event = sample_client()->webhooks()->verify($rawBody, getallheaders() ?: [], sample_required_env('LUNIXI_WEBHOOK_SECRET'));
} catch (WebhookVerificationException $e) {
    http_response_code(400);
    echo 'invalid signature';
    return;
}

if (in_array($event->type(), InsuranceEvents::LEAD_EVENTS, true)) {
    // A lead summary, not an order.
    $lead = InsuranceLeadWebhookData::fromEvent($event)->lead();
    error_log(sprintf('insurance lead %s %s %s', $lead->leadId(), $lead->insuranceType(), $lead->status()));
} elseif ($event->matches(InsuranceEvents::ALL_EVENTS)) {
    $order = InsuranceWebhookData::fromEvent($event);
    switch ($event->type()) {
        case InsuranceEvents::POLICY_ISSUED:
            // Store the policies (policyId, policyNumber) against $order->merchantOrderId().
            error_log(sprintf('insurance issued %s %d', $order->insuranceOrderId(), count($order->policies())));
            break;
        case InsuranceEvents::POLICY_FAILED:
        case InsuranceEvents::REFUND_REQUIRED:
            error_log(sprintf('insurance needs attention %s %s', $order->insuranceOrderId(), (string) $order->errorCode()));
            break;
        case InsuranceEvents::POLICY_DOCUMENT_READY:
            // documentUrl() is downloaded with a signed call: insurance()->getPolicyDocument($policyId).
            error_log(sprintf('insurance document ready %s', $order->insuranceOrderId()));
            break;
        default:
            error_log(sprintf('insurance %s %s %s', $event->type(), $order->insuranceOrderId(), $order->status()));
    }
}

http_response_code(200);
echo 'ok';
