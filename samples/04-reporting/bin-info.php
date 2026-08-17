<?php

declare(strict_types=1);

require_once __DIR__ . '/../_common/bootstrap.php';

$binOrPan = sample_env('LUNIXI_BIN_OR_PAN', '54000000');

sample_print(sample_client()->payments()->binInfo($binOrPan));
