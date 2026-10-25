<?php

declare(strict_types=1);

require_once __DIR__ . '/../_common/bootstrap.php';

// The payment methods open for your account: for each method the block name, the
// providers you can choose and the fields each provider asks the buyer for.
foreach (sample_client()->payments()->listPaymentMethods()->methods() as $method) {
    printf("%s (%s, picked by %s) block=%s preauth=%s\n", $method->paymentMethod(), $method->providerSelection(), $method->providerSelectionMode() ?? '-', $method->blockName(), $method->supportsPreauth() ? 'yes' : 'no');
    foreach ($method->providers() as $provider) {
        $fields = array_map(
            static fn ($field): string => $field->key() . ':' . $field->type() . ($field->isRequired() ? '*' : ''),
            $provider->inputFields()
        );
        printf("  %s %s [%s]\n", $provider->providerCode(), $provider->displayName(), implode(', ', $fields));
    }
}
