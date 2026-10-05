<?php

/**
 * "Is this payment fraud?" — the standalone decision call.
 *
 * Use this when you run your own gateway or POS, or when you are a payment
 * institution scoring your own sub-merchants. The decision comes back before you
 * capture, with the reason codes that produced it.
 */

declare(strict_types=1);

require_once __DIR__ . '/../_common/bootstrap.php';

use Lunixi\Sdk\Fraud\EvaluateRequest;

$fraud = sample_client()->fraud();

$request = (new EvaluateRequest())
    ->withSubjectType('card_payment')
    ->withProductContext('direct_api')
    ->withPayment([
        'amount' => 250000, // minor units: 250000 = 2,500.00
        'currency' => 'TRY',
        'is3D' => false,
        'country' => 'TR',
    ])
    ->withCustomer([
        'id' => 'cus_88',
        'email' => 'buyer@example.com',
        'isNew' => true,
    ])
    ->withDevice([
        'fingerprintId' => 'dev_9f2c',
        'ipAddress' => '203.0.113.7',
    ]);

$decision = $fraud->evaluate($request);

sample_print([
    'decision' => $decision->decision(),
    'score' => $decision->score(),
    'reasonCodes' => $decision->reasonCodes(),
    'matchedRules' => $decision->matchedRules(),
    'explainability' => $decision->explainabilitySummary(),
    // The only value worth storing: it reads the decision back later.
    'traceId' => $decision->traceId(),
]);

// Apply the verdict in YOUR authorisation step. `force_3d` means "do not take
// this one without step-up authentication".
if ($decision->isBlock()) {
    sample_print(['action' => 'do not capture']);
} elseif ($decision->isForce3D()) {
    sample_print(['action' => 'send the cardholder through 3-D Secure']);
} elseif ($decision->isReview()) {
    sample_print(['action' => 'capture and queue for manual review']);
}
