<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Tests\Support;

use Lunixi\Sdk\Http\HttpClientInterface;
use Lunixi\Sdk\Http\HttpResponse;

/**
 * Test transport: returns queued responses (or throws queued exceptions) and
 * records every request for assertions. No network.
 */
final class FakeHttpClient implements HttpClientInterface
{
    /** @var array<int,array{method:string,url:string,headers:array<string,string>,body:?string}> */
    public array $calls = [];

    /** @var array<int,mixed> HttpResponse|\Throwable, consumed FIFO. */
    private array $queue = [];

    /** @param mixed $responseOrThrowable HttpResponse to return or \Throwable to throw. */
    public function push($responseOrThrowable): self
    {
        $this->queue[] = $responseOrThrowable;
        return $this;
    }

    public function pushJson(int $status, array $body, array $headers = []): self
    {
        return $this->push(new HttpResponse($status, $headers, (string) json_encode($body)));
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): HttpResponse
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        if ($this->queue === []) {
            throw new \RuntimeException('FakeHttpClient: no queued response for ' . $method . ' ' . $url);
        }
        $next = array_shift($this->queue);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    /** @return array{method:string,url:string,headers:array<string,string>,body:?string} */
    public function lastCall(): array
    {
        return $this->calls[count($this->calls) - 1];
    }

    public function callCount(): int
    {
        return count($this->calls);
    }
}
