<?php

declare(strict_types=1);

require_once __DIR__ . '/../_common/bootstrap.php';

sample_print(
    sample_client()->payments()->providerCredentialAnalytics(
        sample_required_env('LUNIXI_PROVIDER_CREDENTIAL_ID'),
        ['range' => sample_env('LUNIXI_ANALYTICS_RANGE', '30d')]
    )
);
