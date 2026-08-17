<?php

declare(strict_types=1);

use Lunixi\Sdk\LunixiClient;
use Lunixi\Sdk\Payment\Address;
use Lunixi\Sdk\Payment\BasketItem;
use Lunixi\Sdk\Payment\Buyer;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

sample_load_env(__DIR__ . '/../.env');

function sample_client(): LunixiClient
{
    return LunixiClient::create([
        'baseUrl' => sample_env('LUNIXI_BASE_URL', 'https://api.lunixi.io'),
        'environment' => sample_env('LUNIXI_ENVIRONMENT', 'TEST'),
        'keyId' => sample_required_env('LUNIXI_KEY_ID'),
        'privateKey' => sample_private_key(),
    ]);
}

function sample_private_key(): string
{
    $path = sample_env('LUNIXI_PRIVATE_KEY_PATH');
    if ($path !== null && $path !== '') {
        $key = @file_get_contents($path);
        if ($key === false || trim($key) === '') {
            throw new RuntimeException('LUNIXI_PRIVATE_KEY_PATH could not be read.');
        }
        return $key;
    }

    $inline = sample_env('LUNIXI_PRIVATE_KEY');
    if ($inline !== null && $inline !== '') {
        return str_replace('\n', "\n", $inline);
    }

    throw new RuntimeException('Set LUNIXI_PRIVATE_KEY_PATH or LUNIXI_PRIVATE_KEY.');
}

function sample_env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return $value === false || $value === '' ? $default : $value;
}

function sample_required_env(string $key): string
{
    $value = sample_env($key);
    if ($value === null || trim($value) === '') {
        throw new RuntimeException("Missing required environment variable: {$key}");
    }
    return $value;
}

function sample_idempotency_key(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(12));
}

function sample_buyer(): Buyer
{
    return new Buyer([
        'name' => sample_env('LUNIXI_BUYER_NAME', 'Ada'),
        'surname' => sample_env('LUNIXI_BUYER_SURNAME', 'Yilmaz'),
        'identityNumber' => sample_env('LUNIXI_BUYER_IDENTITY_NUMBER', '11111111111'),
        'email' => sample_env('LUNIXI_BUYER_EMAIL', 'ada@example.com'),
        'gsmNumber' => sample_env('LUNIXI_BUYER_GSM', '+905350000000'),
        'city' => sample_env('LUNIXI_BUYER_CITY', 'Istanbul'),
        'country' => sample_env('LUNIXI_BUYER_COUNTRY', 'Turkey'),
        'zipCode' => sample_env('LUNIXI_BUYER_ZIP', '34000'),
        'ip' => sample_env('LUNIXI_BUYER_IP', '127.0.0.1'),
    ]);
}

function sample_billing_address(): Address
{
    return new Address([
        'address' => sample_env('LUNIXI_BILLING_ADDRESS', 'Example Street 1'),
        'zipCode' => sample_env('LUNIXI_BILLING_ZIP', '34000'),
        'contactName' => sample_env('LUNIXI_BILLING_CONTACT', 'Ada Yilmaz'),
        'city' => sample_env('LUNIXI_BILLING_CITY', 'Istanbul'),
        'country' => sample_env('LUNIXI_BILLING_COUNTRY', 'Turkey'),
    ]);
}

/** @return BasketItem[] */
function sample_basket_items(int $amount): array
{
    return [
        new BasketItem([
            'id' => sample_env('LUNIXI_BASKET_ITEM_ID', 'SKU-001'),
            'price' => $amount,
            'name' => sample_env('LUNIXI_BASKET_ITEM_NAME', 'Demo Product'),
            'category1' => sample_env('LUNIXI_BASKET_ITEM_CATEGORY', 'Demo'),
            'itemType' => BasketItem::TYPE_PHYSICAL,
        ]),
    ];
}

/** @param mixed $value */
function sample_print($value): void
{
    echo json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

function sample_load_env(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"')
            || (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
            $value = substr($value, 1, -1);
        }
        if (getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}
