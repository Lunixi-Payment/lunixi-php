<?php

declare(strict_types=1);

require $argv[1];

error_reporting(E_ALL);
set_error_handler(static function (int $level, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $level, $file, $line);
});

try {
    $response = (new Lunixi\Sdk\Http\CurlHttpClient())->send(
        'POST',
        $argv[2],
        ['Content-Type' => 'text/plain'],
        'synthetic-body',
        2.0
    );
    echo json_encode([
        'kind' => 'response',
        'status' => $response->statusCode(),
        'body' => $response->body(),
        'headers' => $response->headers(),
    ], JSON_THROW_ON_ERROR);
} catch (Lunixi\Sdk\Exception\ApiException $error) {
    echo json_encode([
        'kind' => 'transport-error',
        'class' => get_class($error),
        'message' => $error->getMessage(),
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage());
    exit(2);
} finally {
    restore_error_handler();
}
