<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Auth;

/**
 * Builds the canonical string that is Ed25519-signed for every step-up request,
 * byte-for-byte matching the gateway's SignatureGuard.createCanonicalString:
 *
 *   <METHOD>\n
 *   <URL>\n
 *   X-Date:<value>\n
 *   X-Nonce:<value>
 *   [\nDigest:<value>]        // appended ONLY when a body digest is present
 *
 * Lines are joined with "\n". METHOD is upper-cased. The Digest line is omitted
 * entirely for bodyless requests (the gateway only appends it when the Digest
 * header is sent). Any drift here makes every signature fail verification.
 */
final class CanonicalRequest
{
    public const HEADER_KEY_ID = 'X-Key-Id';
    public const HEADER_DATE = 'X-Date';
    public const HEADER_NONCE = 'X-Nonce';
    public const HEADER_SIGNATURE = 'X-Signature';
    public const HEADER_DIGEST = 'Digest';

    /** Body digest prefix the gateway expects: "SHA-256=<base64(sha256(body))>". */
    public const DIGEST_PREFIX = 'SHA-256=';

    /**
     * @param string      $method HTTP method (any case; upper-cased here).
     * @param string      $url    The request URL/path exactly as sent.
     * @param string      $date   X-Date header value (ISO-8601).
     * @param string      $nonce  X-Nonce header value (unique per request).
     * @param string|null $digest Digest header value ("SHA-256=…") when a body is sent; null otherwise.
     */
    public static function build(
        string $method,
        string $url,
        string $date,
        string $nonce,
        ?string $digest = null
    ): string {
        $lines = [
            strtoupper($method),
            $url,
            self::HEADER_DATE . ':' . $date,
            self::HEADER_NONCE . ':' . $nonce,
        ];

        if ($digest !== null && $digest !== '') {
            $lines[] = self::HEADER_DIGEST . ':' . $digest;
        }

        return implode("\n", $lines);
    }

    /**
     * Computes the Digest header value for a raw request body:
     *   "SHA-256=" . base64(sha256(rawBody))
     * Returns null for an empty body (bodyless requests carry no Digest).
     */
    public static function digestForBody(string $rawBody): ?string
    {
        if ($rawBody === '') {
            return null;
        }

        return self::DIGEST_PREFIX . base64_encode(hash('sha256', $rawBody, true));
    }
}
