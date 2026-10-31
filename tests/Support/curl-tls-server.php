<?php

declare(strict_types=1);

$context = stream_context_create([
    'ssl' => [
        'local_cert' => $argv[1],
        'local_pk' => $argv[2],
        'verify_peer' => false,
    ],
]);
$server = stream_socket_server(
    'tls://127.0.0.1:0',
    $errno,
    $error,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    $context
);
if ($server === false) {
    exit(2);
}

$address = 'tcp://' . stream_socket_get_name($server, false);
fwrite(STDOUT, (string) parse_url($address, PHP_URL_PORT) . "\n");
fflush(STDOUT);

while ($client = @stream_socket_accept($server, 10)) {
    stream_set_timeout($client, 5);
    $first = fgets($client);
    $length = 0;
    while (($line = fgets($client)) !== false && trim($line) !== '') {
        if (stripos($line, 'Content-Length:') === 0) {
            $length = (int) trim(substr($line, 15));
        }
    }
    $body = $length > 0 ? stream_get_contents($client, $length) : '';
    $path = explode(' ', (string) $first)[1] ?? '/';
    file_put_contents($argv[3], $path . "\n", FILE_APPEND | LOCK_EX);

    $redirect = $path === '/redirect';
    $payload = $redirect ? 'redirect' : 'received:' . $body;
    $response = 'HTTP/1.1 ' . ($redirect ? '302 Found' : '200 OK')
        . "\r\nContent-Type: text/plain\r\nX-Fixture: tls\r\n";
    if ($redirect) {
        $response .= "Location: /must-not-be-followed\r\n";
    }
    $response .= 'Content-Length: ' . strlen($payload)
        . "\r\nConnection: close\r\n\r\n" . $payload;
    fwrite($client, $response);
    fclose($client);
}
