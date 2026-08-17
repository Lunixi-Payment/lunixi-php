<?php

declare(strict_types=1);

use Lunixi\Sdk\Auth\Ed25519Signer;

require_once __DIR__ . '/../_common/bootstrap.php';

$keys = Ed25519Signer::generateKeyPair();

sample_print([
    'privateKeyPem' => $keys['privateKey'],
    'publicKeyPem' => $keys['publicKey'],
    'nextStep' => 'Register publicKeyPem in the Lunixi dashboard. Store privateKeyPem securely and never commit it.',
]);
