<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Tests\Http;

use PHPUnit\Framework\TestCase;

/** Actual cURL/TLS subprocesses reproduce strict platform handlers, including PHP 8.5. */
final class CurlHttpClientTest extends TestCase
{
    private string $directory;

    /** @var resource|null */
    private $server = null;

    /** @var list<resource> */
    private array $pipes = [];

    private int $port;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/lunixi-curl-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $config = $this->directory . '/openssl.cnf';
        file_put_contents(
            $config,
            "[req]\ndistinguished_name=dn\n[dn]\n[v3]\n"
                . "subjectAltName=IP:127.0.0.1\nbasicConstraints=critical,CA:TRUE\n"
        );
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($key);
        $csr = openssl_csr_new(
            ['commonName' => '127.0.0.1'],
            $key,
            ['config' => $config, 'digest_alg' => 'sha256']
        );
        self::assertNotFalse($csr);
        $certificate = openssl_csr_sign($csr, null, $key, 1, [
            'config' => $config,
            'x509_extensions' => 'v3',
            'digest_alg' => 'sha256',
        ]);
        self::assertNotFalse($certificate);
        openssl_pkey_export_to_file($key, $this->directory . '/key.pem');
        openssl_x509_export_to_file($certificate, $this->directory . '/cert.pem');
        $this->server = proc_open([
            PHP_BINARY,
            __DIR__ . '/../Support/curl-tls-server.php',
            $this->directory . '/cert.pem',
            $this->directory . '/key.pem',
            $this->directory . '/requests',
        ], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        self::assertIsResource($this->server);
        $this->pipes = $pipes;
        stream_set_timeout($pipes[1], 10);
        $port = trim((string) fgets($pipes[1]));
        self::assertMatchesRegularExpression('/^[0-9]+$/D', $port);
        $this->port = (int) $port;
    }

    protected function tearDown(): void
    {
        $this->stopServer();
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    private function stopServer(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            foreach ($this->pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($this->server);
            $this->server = null;
        }
    }

    /** @return array<string,mixed> */
    private function send(string $path): array
    {
        $process = proc_open([
            PHP_BINARY,
            '-d',
            'openssl.cafile=' . $this->directory . '/cert.pem',
            '-d',
            'curl.cainfo=' . $this->directory . '/cert.pem',
            __DIR__ . '/../Support/curl-strict-worker.php',
            dirname(__DIR__, 2) . '/vendor/autoload.php',
            'https://127.0.0.1:' . $this->port . $path,
        ], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), (string) $error);
        return json_decode((string) $output, true, 32, JSON_THROW_ON_ERROR);
    }

    public function testRealTlsSuccessUnderStrictErrorHandler(): void
    {
        $result = $this->send('/ok');
        self::assertSame('response', $result['kind'], json_encode($result));
        self::assertSame(200, $result['status']);
        self::assertSame('received:synthetic-body', $result['body']);
        self::assertSame('tls', $result['headers']['x-fixture']);
    }

    public function testRealTlsRedirectIsReturnedWithoutFollowingIt(): void
    {
        $result = $this->send('/redirect');
        self::assertSame(302, $result['status']);
        self::assertSame('redirect', $result['body']);
        self::assertSame("/redirect\n", file_get_contents($this->directory . '/requests'));
    }

    public function testConnectionFailurePreservesTypedTransportError(): void
    {
        $this->stopServer();
        $result = $this->send('/unavailable');
        self::assertSame('transport-error', $result['kind']);
        self::assertSame(\Lunixi\Sdk\Exception\ApiException::class, $result['class']);
    }
}
