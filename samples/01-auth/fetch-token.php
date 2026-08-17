<?php

declare(strict_types=1);

require_once __DIR__ . '/../_common/bootstrap.php';

$client = sample_client();

sample_print([
    'accessToken' => $client->tokens()->getToken(),
    'tokenCacheKey' => $client->config()->tokenCacheKey(),
]);
