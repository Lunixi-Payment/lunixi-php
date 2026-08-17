<?php

declare(strict_types=1);

require_once __DIR__ . '/../_common/bootstrap.php';

sample_print(
    sample_client()->payments()->deactivateStoredCard(
        sample_required_env('LUNIXI_STORED_CARD_TOKEN'),
        sample_idempotency_key('deactivate-card')
    )
);
