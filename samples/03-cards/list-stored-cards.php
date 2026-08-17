<?php

declare(strict_types=1);

require_once __DIR__ . '/../_common/bootstrap.php';

$cardUserKey = sample_env('LUNIXI_CARD_USER_KEY', sample_env('LUNIXI_CUSTOMER_ID', 'cust_demo_001'));

sample_print(sample_client()->payments()->listStoredCards($cardUserKey));
