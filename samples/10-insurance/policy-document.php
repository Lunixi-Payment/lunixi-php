<?php

declare(strict_types=1);

/*
 * Downloads a policy PDF. The document is read from the provider on every call
 * and is available while the policy is in force; 503
 * `insurance.document.unavailable` is temporary — retry later.
 */

use Lunixi\Sdk\Exception\ApiException;

require_once __DIR__ . '/../_common/bootstrap.php';

$policyId = sample_required_env('LUNIXI_INSURANCE_POLICY_ID');

try {
    $document = sample_client()->insurance()->getPolicyDocument($policyId);
    $file = $policyId . '.pdf';
    file_put_contents($file, $document->bytes());
    sample_print(['file' => $file, 'contentType' => $document->contentType(), 'bytes' => strlen($document->bytes())]);
} catch (ApiException $e) {
    sample_print(['status' => $e->getStatusCode(), 'code' => $e->getErrorCode()]);
}
