<?php

declare(strict_types=1);

use Lunixi\Sdk\Payment\InstallmentOptionsRequest;

require_once __DIR__ . '/../_common/bootstrap.php';

$request = (new InstallmentOptionsRequest((int) sample_env('LUNIXI_AMOUNT', '10050'), 'TRY'))
    ->withBinOrPan(sample_env('LUNIXI_BIN_OR_PAN', '54000000'))
    ->withInstallment((int) sample_env('LUNIXI_INSTALLMENT', '3'))
    ->withFormat('data');

sample_print(sample_client()->payments()->installmentOptions($request));
