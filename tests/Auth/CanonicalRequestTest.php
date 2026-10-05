<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Tests\Auth;

use Lunixi\Sdk\Auth\CanonicalRequest;
use PHPUnit\Framework\TestCase;

/**
 * Locks the canonical string byte-for-byte against the gateway's
 * SignatureGuard.createCanonicalString. Any drift breaks every signature.
 */
final class CanonicalRequestTest extends TestCase
{
    public function testBuildsBodylessCanonicalWithoutDigestLine(): void
    {
        $canonical = CanonicalRequest::build('post', '/api/v1/auth/token', '2026-06-15T12:00:00Z', 'nonce-1');

        $this->assertSame(
            "POST\n/api/v1/auth/token\nX-Date:2026-06-15T12:00:00Z\nX-Nonce:nonce-1",
            $canonical
        );
    }

    public function testAppendsDigestLineWhenPresentAndUppercasesMethod(): void
    {
        $canonical = CanonicalRequest::build(
            'POST',
            '/api/v1/payments/intents',
            '2026-06-15T12:00:00Z',
            'nonce-2',
            'SHA-256=abc'
        );

        $this->assertSame(
            "POST\n/api/v1/payments/intents\nX-Date:2026-06-15T12:00:00Z\nX-Nonce:nonce-2\nDigest:SHA-256=abc",
            $canonical
        );
    }

    public function testEmptyDigestIsTreatedAsNoDigest(): void
    {
        $withEmpty = CanonicalRequest::build('GET', '/x', 'd', 'n', '');
        $withNull = CanonicalRequest::build('GET', '/x', 'd', 'n', null);

        $this->assertSame($withNull, $withEmpty);
        $this->assertStringNotContainsString('Digest:', $withEmpty);
    }

    public function testDigestForBodyMatchesGatewayFormula(): void
    {
        $body = '{"amount":1000}';
        $expected = 'SHA-256=' . base64_encode(hash('sha256', $body, true));

        $this->assertSame($expected, CanonicalRequest::digestForBody($body));
        $this->assertNull(CanonicalRequest::digestForBody(''), 'empty body carries no digest');
    }
}
