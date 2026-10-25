<?php

declare(strict_types=1);

/*
 * Lunixi reference codes for the insurance type fields that take a code
 * (province, district, device and vehicle brands and models …). Districts
 * need the province code as `parentCode`.
 */

use Lunixi\Sdk\Insurance\InsuranceReferenceKind;

require_once __DIR__ . '/../_common/bootstrap.php';

$province = sample_env('LUNIXI_PROVINCE_CODE', '35');

foreach (sample_client()->insurance()->iterateReferenceValues(InsuranceReferenceKind::DISTRICT, ['parentCode' => $province, 'limit' => 100]) as $district) {
    $label = $district->label();
    sample_print(['code' => $district->code(), 'label' => $label['tr'] ?? $label['en'] ?? null]);
}
