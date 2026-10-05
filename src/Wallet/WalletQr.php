<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Wallet;

use Generator;
use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Http\Envelope;

/**
 * Wallet QR codes (`$lunixi->wallet()->qr`, scope `wallet:qr:manage`).
 */
final class WalletQr
{
    private const SCALE_MIN = 1;
    private const SCALE_MAX = 20;

    private WalletInvoker $invoker;

    public function __construct(WalletInvoker $invoker)
    {
        $this->invoker = $invoker;
    }

    /**
     * Creates a QR code. Static codes carry no amount; dynamic ones require it.
     *
     * @param array<string,mixed> $request qrType (WalletQrType), currency; optional targetEndUserId,
     *   targetExternalCustomerId, programId, amount (minor units), expiresInSeconds, metadata.
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function create(array $request, string $idempotencyKey): array
    {
        return Envelope::data($this->invoker->call('createQr', [], $request, $idempotencyKey));
    }

    /**
     * @param array<string,mixed> $filters programId, qrType, status, targetEndUserId, limit, cursor.
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function list(array $filters = []): WalletPage
    {
        return new WalletPage($this->invoker->call('listQr', [], null, null, $filters));
    }

    /**
     * @param array<string,mixed> $filters See {@see list()}.
     * @return Generator<int,array<string,mixed>>
     */
    public function iterate(array $filters = []): Generator
    {
        return WalletPage::paginate(fn (array $page): WalletPage => $this->list($page), $filters);
    }

    /**
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function get(string $qrId): array
    {
        return Envelope::data($this->invoker->call('getQr', ['qrId' => $qrId]));
    }

    /**
     * The QR code as an image.
     *
     * @param array{format?:string, scale?:int} $options format: WalletQrImageFormat (server default svg);
     *   scale: 1-20.
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function image(string $qrId, array $options = []): WalletQrImage
    {
        $format = $options['format'] ?? null;
        if ($format !== null && !in_array($format, WalletQrImageFormat::ALL, true)) {
            throw new ConfigurationException('QR image format must be one of ' . implode(', ', WalletQrImageFormat::ALL) . '.');
        }
        $scale = $options['scale'] ?? null;
        if ($scale !== null && (!is_int($scale) || $scale < self::SCALE_MIN || $scale > self::SCALE_MAX)) {
            throw new ConfigurationException(sprintf('QR image scale must be an integer %d-%d.', self::SCALE_MIN, self::SCALE_MAX));
        }

        $response = $this->invoker->callBinary('getQrImage', ['qrId' => $qrId], $options);

        return new WalletQrImage((string) $response->header('content-type'), $response->body());
    }

    /**
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function revoke(string $qrId): array
    {
        return Envelope::data($this->invoker->call('revokeQr', ['qrId' => $qrId]));
    }

    /**
     * Decodes a scanned QR payload.
     *
     * @return array<string,mixed>
     * @throws ConfigurationException
     * @throws ApiException
     */
    public function parse(string $payload): array
    {
        return Envelope::data($this->invoker->call('parseQr', [], ['payload' => $payload]));
    }
}
