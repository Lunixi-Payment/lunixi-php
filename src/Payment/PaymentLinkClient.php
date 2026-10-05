<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Payment;

use DateTimeInterface;
use Generator;
use Lunixi\Sdk\ApiClient;
use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Http\CurlHttpClient;
use Lunixi\Sdk\Http\HttpClientInterface;

/**
 * Payment links — shareable payment pages at `https://pay.lunixi.com/<code>`.
 *
 *   create            POST  /api/v1/payments/links                 (Idempotency-Key required)
 *   list / iterate    GET   /api/v1/payments/links
 *   get               GET   /api/v1/payments/links/{id}
 *   update            PATCH /api/v1/payments/links/{id}            (new version)
 *   pause / resume /
 *   deactivate        POST  /api/v1/payments/links/{id}/{action}
 *   send              POST  /api/v1/payments/links/{id}/send       (Idempotency-Key required)
 *   qr                GET   /api/v1/payments/links/{id}/qr         (image bytes)
 *   listAttempts      GET   /api/v1/payments/links/{id}/attempts
 *   listPayments      GET   /api/v1/payments/links/{id}/payments
 *   createImageUpload POST  /api/v1/payments/links/images          (link image, step 1 of 3)
 *   confirmImageUpload POST /api/v1/payments/links/images/{assetId}/confirm (step 3 of 3)
 *   uploadImage       all three steps; step 2 is a PUT to the storage host
 *
 * These routes are authenticated by the API key's per-request Ed25519
 * signature, so every gateway call here is sent step-up (signed), reads
 * included. A request without the signature is 401 `AUTH_HEADERS_MISSING`; a
 * panel session cannot call them. The key needs the matching scope
 * (`payment-link:create`, `:view`, `:manage`, `:send`; otherwise 403
 * `API_INSUFFICIENT_SCOPE`) and decides the environment: a TEST key sees and
 * changes only TEST links.
 *
 * The one request that is not signed is the image PUT in uploadImage(): it goes
 * to the storage host behind a presigned URL, not to the gateway, and carries
 * no credentials at all.
 *
 * Fulfil orders from the `payment.captured` webhook — its payload carries
 * `paymentLinkId` and `paymentLinkCode` — not from these reads.
 */
final class PaymentLinkClient
{
    public const CHANNEL_EMAIL = 'EMAIL';
    public const CHANNEL_SMS = 'SMS';

    public const QR_SVG = 'svg';
    public const QR_PNG = 'png';

    /** Declarable link image types. The stored bytes must match the declared type; SVG is never accepted. */
    public const IMAGE_PNG = 'image/png';
    public const IMAGE_JPEG = 'image/jpeg';
    public const IMAGE_WEBP = 'image/webp';

    private const BASE = '/api/v1/payments/links';
    private const IMAGES = self::BASE . '/images';
    private const IMAGE_CONTENT_TYPES = [self::IMAGE_PNG, self::IMAGE_JPEG, self::IMAGE_WEBP];
    private const UPLOAD_URL_SCHEMES = ['https', 'http'];

    /** `Idempotency-Key` format the gateway accepts: 8–200 printable ASCII characters, no spaces. */
    private const IDEMPOTENCY_KEY_PATTERN = '/^[\x21-\x7E]{8,200}$/';

    private const ACTIONS = ['pause', 'resume', 'deactivate'];

    private const LINK_FILTERS = [
        'limit', 'cursor', 'includeTotal', 'state', 'usage', 'amountMode', 'environment',
        'tag', 'branchCode', 'createdById', 'query', 'createdFrom', 'createdTo',
    ];
    private const ATTEMPT_FILTERS = ['limit', 'cursor', 'includeTotal', 'state'];
    private const PAYMENT_FILTERS = ['limit', 'cursor', 'includeTotal'];

    private const QR_MIN_SIZE = 128;
    private const QR_MAX_SIZE = 2048;

    private ApiClient $api;

    /** Transport for the image PUT to the storage host only; gateway calls go through ApiClient. */
    private HttpClientInterface $storage;

    private float $storageTimeout;

    /**
     * @param HttpClientInterface|null $storage        Transport for the image upload's PUT to the storage host
     *                                                 (LunixiClient passes its own transport). Defaults to cURL.
     * @param float                    $storageTimeout Seconds allowed for that PUT.
     */
    public function __construct(ApiClient $api, ?HttpClientInterface $storage = null, float $storageTimeout = 30.0)
    {
        $this->api = $api;
        $this->storage = $storage ?? new CurlHttpClient();
        $this->storageTimeout = $storageTimeout;
    }

    /**
     * Creates a payment link.
     *
     * The same key with the same body returns the stored link (HTTP 200,
     * {@see PaymentLink::wasReplayed()}) instead of creating a second one; the
     * same key with a different body is 409 `payment_link.idempotency.conflict`.
     *
     * @throws ApiException
     */
    public function create(CreatePaymentLinkRequest $request, string $idempotencyKey): PaymentLink
    {
        $response = $this->api->request('POST', self::BASE, self::encodeObjects($request->toArray()), [
            'stepUp' => true,
            'idempotencyKey' => self::requireIdempotencyKey('payment link create', $idempotencyKey),
        ]);

        return new PaymentLink(self::dataOf($response));
    }

    /**
     * One page of links, newest first.
     *
     * `state`, `usage` and `amountMode` accept a string or a list of strings.
     * `createdFrom`/`createdTo` (DateTimeInterface or ISO-8601) bound a
     * half-open window `[from, to)`. `query` matches a short code exactly, or a
     * title/reference prefix.
     *
     * @param array<string,mixed> $filters limit (1–100), cursor, includeTotal, state, usage,
     *   amountMode, environment, tag, branchCode, createdById, query, createdFrom, createdTo.
     * @return PaymentLinkList<PaymentLink>
     */
    public function list(array $filters = []): PaymentLinkList
    {
        $response = $this->api->request('GET', self::BASE, null, [
            'stepUp' => true,
            'query' => self::query($filters, self::LINK_FILTERS),
        ]);

        return new PaymentLinkList($response, static fn (array $row): PaymentLink => new PaymentLink($row));
    }

    /**
     * Every link matching the filters, following the cursors page by page.
     *
     * @param array<string,mixed> $filters See {@see list()}.
     * @return Generator<int,PaymentLink>
     */
    public function iterate(array $filters = []): Generator
    {
        return self::paginate(fn (array $page): PaymentLinkList => $this->list($page), $filters);
    }

    /** @throws ApiException 404 `payment_link.link.not_found` for another organisation's or environment's link. */
    public function get(string $linkId): PaymentLink
    {
        $response = $this->api->request('GET', self::linkPath($linkId), null, ['stepUp' => true]);

        return new PaymentLink(self::dataOf($response));
    }

    /**
     * Changes a link and creates a new version of it.
     *
     * Only the keys in `$changes` change; `null` clears a field and a nested
     * object (`recipient`, `checkout`, …) is replaced as a whole. Items and
     * custom fields may be given as PaymentLinkItem / PaymentLinkCustomField.
     * `$expectedRowVersion` is the link's current rowVersion: if the link
     * changed in between, the gateway answers 409
     * `payment_link.link.version_conflict`. Amount fields are locked (409
     * `payment_link.link.amount_locked`) while a payment is in progress or once
     * the link has been paid.
     *
     * @param array<string,mixed> $changes Keys from CreatePaymentLinkRequest::FIELDS,
     *   except usage, amountMode and currency.
     * @throws ApiException
     */
    public function update(string $linkId, int $expectedRowVersion, array $changes, ?string $idempotencyKey = null): PaymentLink
    {
        if ($expectedRowVersion < 0) {
            throw new ConfigurationException("'expectedRowVersion' must be the link's current rowVersion (a non-negative integer).");
        }
        $body = ['expectedRowVersion' => $expectedRowVersion] + self::encodeObjects(self::changesBody($changes));

        $response = $this->api->request('PATCH', self::linkPath($linkId), $body, [
            'stepUp' => true,
            'idempotencyKey' => self::optionalIdempotencyKey($idempotencyKey),
        ]);

        return new PaymentLink(self::dataOf($response));
    }

    /** Stops accepting payments until resume(). */
    public function pause(string $linkId, ?int $expectedRowVersion = null, ?string $reason = null, ?string $idempotencyKey = null): PaymentLink
    {
        return $this->action($linkId, 'pause', $expectedRowVersion, $reason, $idempotencyKey);
    }

    public function resume(string $linkId, ?int $expectedRowVersion = null, ?string $reason = null, ?string $idempotencyKey = null): PaymentLink
    {
        return $this->action($linkId, 'resume', $expectedRowVersion, $reason, $idempotencyKey);
    }

    /** Permanently closes the link; it cannot be reopened. */
    public function deactivate(string $linkId, ?int $expectedRowVersion = null, ?string $reason = null, ?string $idempotencyKey = null): PaymentLink
    {
        return $this->action($linkId, 'deactivate', $expectedRowVersion, $reason, $idempotencyKey);
    }

    /**
     * `pause`, `resume` or `deactivate`. Without `$expectedRowVersion` there is
     * no version check; with it, a concurrent change is 409
     * `payment_link.link.version_conflict`. An invalid transition is 409
     * `payment_link.link.invalid_transition`.
     */
    public function action(
        string $linkId,
        string $action,
        ?int $expectedRowVersion = null,
        ?string $reason = null,
        ?string $idempotencyKey = null
    ): PaymentLink {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new ConfigurationException('Payment link action must be one of ' . implode(', ', self::ACTIONS) . '.');
        }
        $body = [];
        if ($expectedRowVersion !== null) {
            if ($expectedRowVersion < 0) {
                throw new ConfigurationException("'expectedRowVersion' must be a non-negative integer.");
            }
            $body['expectedRowVersion'] = $expectedRowVersion;
        }
        if ($reason !== null && $reason !== '') {
            $body['reason'] = $reason;
        }

        // An action without fields is sent without a body (the gateway reads it as `{}`).
        $response = $this->api->request('POST', self::linkPath($linkId) . '/' . $action, $body === [] ? null : $body, [
            'stepUp' => true,
            'idempotencyKey' => self::optionalIdempotencyKey($idempotencyKey),
        ]);

        return new PaymentLink(self::dataOf($response));
    }

    /**
     * Sends the link by e-mail or SMS. Without a recipient in `$options` the
     * link's own `recipient` is used. The Idempotency-Key is required: reuse it
     * when retrying so the buyer is not messaged twice.
     *
     * A send can be accepted and still fail — check `delivery.state` and
     * `delivery.failureReason`. With a TEST key only your organisation's own
     * verified member addresses receive messages
     * (`test_mode_recipient_not_allowed` otherwise).
     *
     * @param array{recipientEmail?:string, recipientPhone?:string, locale?:string} $options
     * @return array{delivery: array<string,mixed>|null, replayed: bool}
     * @throws ApiException
     */
    public function send(string $linkId, string $channel, string $idempotencyKey, array $options = []): array
    {
        if (!in_array($channel, [self::CHANNEL_EMAIL, self::CHANNEL_SMS], true)) {
            throw new ConfigurationException('Send channel must be EMAIL or SMS.');
        }
        $body = ['channel' => $channel];
        foreach ($options as $key => $value) {
            if (!in_array($key, ['recipientEmail', 'recipientPhone', 'locale'], true)) {
                throw new ConfigurationException("Unknown send option '{$key}'. Allowed: recipientEmail, recipientPhone, locale.");
            }
            if ($value === null || $value === '') {
                continue;
            }
            if ($key === 'locale' && !in_array($value, CreatePaymentLinkRequest::LOCALES, true)) {
                throw new ConfigurationException("Send locale must be one of " . implode(', ', CreatePaymentLinkRequest::LOCALES) . '.');
            }
            $body[$key] = (string) $value;
        }

        $data = self::dataOf($this->api->request('POST', self::linkPath($linkId) . '/send', $body, [
            'stepUp' => true,
            'idempotencyKey' => self::requireIdempotencyKey('payment link send', $idempotencyKey),
        ]));

        return [
            'delivery' => isset($data['delivery']) && is_array($data['delivery']) ? $data['delivery'] : null,
            'replayed' => ($data['replayed'] ?? false) === true,
        ];
    }

    /**
     * The link's QR code image.
     *
     * @param string   $format QR_SVG (default) or QR_PNG.
     * @param int|null $size   Pixels, 128–2048 (server default 512).
     * @throws ApiException
     */
    public function qr(string $linkId, string $format = self::QR_SVG, ?int $size = null): PaymentLinkQrCode
    {
        if (!in_array($format, [self::QR_SVG, self::QR_PNG], true)) {
            throw new ConfigurationException('QR format must be svg or png.');
        }
        $query = ['format' => $format];
        if ($size !== null) {
            if ($size < self::QR_MIN_SIZE || $size > self::QR_MAX_SIZE) {
                throw new ConfigurationException(sprintf('QR size must be %d-%d pixels.', self::QR_MIN_SIZE, self::QR_MAX_SIZE));
            }
            $query['size'] = $size;
        }

        $response = $this->api->requestBinary('GET', self::linkPath($linkId) . '/qr', ['stepUp' => true, 'query' => $query]);

        return new PaymentLinkQrCode((string) $response->header('content-type'), $response->body());
    }

    /**
     * Payment attempts on the link, newest first. Rows: id, state
     * (PaymentLinkAttemptState), linkVersion, paymentId, amountMinor, currency,
     * quantity, lines[], buyerName, buyerEmail, buyerPhone, customFields[],
     * failureCode, outcomeCertainty, reservationState, createdAt, boundAt,
     * finalizedAt.
     *
     * @param array<string,mixed> $filters limit, cursor, includeTotal, state (string or list).
     * @return PaymentLinkList<array<string,mixed>>
     */
    public function listAttempts(string $linkId, array $filters = []): PaymentLinkList
    {
        $response = $this->api->request('GET', self::linkPath($linkId) . '/attempts', null, [
            'stepUp' => true,
            'query' => self::query($filters, self::ATTEMPT_FILTERS),
        ]);

        return new PaymentLinkList($response, static fn (array $row): array => $row);
    }

    /**
     * @param array<string,mixed> $filters See {@see listAttempts()}.
     * @return Generator<int,array<string,mixed>>
     */
    public function iterateAttempts(string $linkId, array $filters = []): Generator
    {
        return self::paginate(fn (array $page): PaymentLinkList => $this->listAttempts($linkId, $page), $filters);
    }

    /**
     * Successful payments on the link. Rows: attemptId, paymentId,
     * paymentStatus, amountMinor, capturedAmountMinor, refundedAmountMinor,
     * currency, paidAt, buyerEmail, receiptNumber.
     *
     * @param array<string,mixed> $filters limit, cursor, includeTotal.
     * @return PaymentLinkList<array<string,mixed>>
     */
    public function listPayments(string $linkId, array $filters = []): PaymentLinkList
    {
        $response = $this->api->request('GET', self::linkPath($linkId) . '/payments', null, [
            'stepUp' => true,
            'query' => self::query($filters, self::PAYMENT_FILTERS),
        ]);

        return new PaymentLinkList($response, static fn (array $row): array => $row);
    }

    /**
     * @param array<string,mixed> $filters See {@see listPayments()}.
     * @return Generator<int,array<string,mixed>>
     */
    public function iteratePayments(string $linkId, array $filters = []): Generator
    {
        return self::paginate(fn (array $page): PaymentLinkList => $this->listPayments($linkId, $page), $filters);
    }

    /**
     * Link image, step 1 of 3: an upload URL for a PNG, JPEG or WebP image
     * (scope `payment-link:create`).
     *
     * Step 2 is a PUT of the file's bytes to `uploadUrl` with exactly
     * `uploadHeaders`, before `expiresAt`; step 3 is confirmImageUpload().
     * uploadImage() runs all three. `uploadUrl` is the storage host, not the
     * gateway: send it no access token and no signature.
     *
     * `$fileName` is recorded only (1–255 characters). A declared size over the
     * image limit (2 MiB) is refused here, before anything is uploaded (400
     * `media.public_asset.too_large`); the stored file is measured again on
     * confirmation.
     *
     * @param string $contentType IMAGE_PNG, IMAGE_JPEG or IMAGE_WEBP (SVG is not accepted).
     * @param int    $sizeBytes   The file's size in bytes.
     * @return array{assetId:string, uploadUrl:string, uploadMethod:string, uploadHeaders:array<string,string>, expiresAt:?string}
     * @throws ApiException also when the answer is not an upload target the SDK can PUT to.
     */
    public function createImageUpload(string $fileName, string $contentType, int $sizeBytes): array
    {
        $response = $this->api->request('POST', self::IMAGES, self::imageDeclaration($fileName, $contentType, $sizeBytes), [
            'stepUp' => true,
        ]);

        return self::uploadTarget($response);
    }

    /**
     * Link image, step 3 of 3: publishes an uploaded image (scope
     * `payment-link:create`).
     *
     * Media checks the stored file: its bytes must be the declared type (PNG,
     * JPEG or WebP; never SVG), at most 2 MiB and 4096×4096 px. A file that
     * fails is deleted (400) and has to be uploaded again from
     * createImageUpload(); confirming before the PUT has landed is 400
     * `media.confirm.object_missing` and can be retried.
     *
     * Use the returned `assetId` as the `imageAssetId` of a link or an item.
     * `url` is null when the public image host is not configured.
     *
     * @return array{assetId:string, url:?string, state:string} `state` is always `PUBLISHED`.
     * @throws ApiException also when the answer is not a published image.
     */
    public function confirmImageUpload(string $assetId): array
    {
        $path = self::IMAGES . '/' . rawurlencode(self::requireAssetId($assetId)) . '/confirm';
        // The route reads no body; like an action without fields, it is sent without one.
        $response = $this->api->request('POST', $path, null, ['stepUp' => true]);

        $image = self::dataOf($response);
        if (!isset($image['assetId']) || !is_string($image['assetId']) || ($image['state'] ?? null) !== 'PUBLISHED') {
            throw new ApiException('Image confirmation did not return a published image.', 0, null, $response);
        }

        return [
            'assetId' => $image['assetId'],
            'url' => isset($image['url']) && is_string($image['url']) ? $image['url'] : null,
            'state' => 'PUBLISHED',
        ];
    }

    /**
     * Uploads a link image in one call: createImageUpload(), the PUT of
     * `$bytes` to the returned `uploadUrl`, then confirmImageUpload(). Returns
     * the published image.
     *
     * The PUT goes straight to the storage host with only `uploadHeaders`,
     * over the plain HTTP transport and not through ApiClient, so the access
     * token and the request signature are never sent there. A failed PUT is not
     * retried: the ApiException carries the storage host's HTTP status (and
     * its error code, e.g. `AccessDenied` once the URL has expired); call
     * uploadImage() again for a new upload URL.
     *
     * @param string $contentType IMAGE_PNG, IMAGE_JPEG or IMAGE_WEBP.
     * @param string $bytes       The file's raw bytes (e.g. from file_get_contents()).
     * @return array{assetId:string, url:?string, state:string}
     * @throws ApiException
     */
    public function uploadImage(string $fileName, string $contentType, string $bytes): array
    {
        if ($bytes === '') {
            throw new ConfigurationException('Image content must not be empty.');
        }
        $target = $this->createImageUpload($fileName, $contentType, strlen($bytes));
        $this->putToStorage($target, $bytes);

        return $this->confirmImageUpload($target['assetId']);
    }

    /**
     * The PUT to the presigned upload URL. It deliberately does NOT use
     * ApiClient: that adds the bearer access token and the Ed25519 signature
     * headers, which must never reach the storage host (a presigned URL also
     * rejects a request that carries an Authorization header). Only the
     * headers the URL was signed for are sent.
     *
     * @param array{uploadUrl:string, uploadHeaders:array<string,string>} $target
     * @throws ApiException
     */
    private function putToStorage(array $target, string $bytes): void
    {
        try {
            $response = $this->storage->send('PUT', $target['uploadUrl'], $target['uploadHeaders'], $bytes, $this->storageTimeout);
        } catch (ApiException $transportError) {
            throw new ApiException('Image upload to storage failed: ' . $transportError->getMessage(), 0, null, null, null, $transportError);
        }
        if (!$response->isSuccess()) {
            $code = self::storageErrorCode($response->body());
            throw new ApiException(
                sprintf('Image upload to storage failed (HTTP %d%s).', $response->statusCode(), $code !== null ? ', ' . $code : ''),
                $response->statusCode(),
                $code
            );
        }
    }

    /**
     * @param callable(array<string,mixed>):PaymentLinkList<mixed> $fetchPage
     * @param array<string,mixed> $filters
     * @return Generator<int,mixed>
     */
    private static function paginate(callable $fetchPage, array $filters): Generator
    {
        do {
            $page = $fetchPage($filters);
            foreach ($page->items() as $item) {
                yield $item;
            }
            $filters['cursor'] = $page->nextCursor();
        } while ($page->hasMore());
    }

    /**
     * @param array<string,mixed> $changes
     * @return array<string,mixed>
     */
    private static function changesBody(array $changes): array
    {
        if ($changes === []) {
            throw new ConfigurationException('Payment link update needs at least one changed field.');
        }
        $body = [];
        foreach ($changes as $key => $value) {
            if (in_array($key, CreatePaymentLinkRequest::IMMUTABLE_FIELDS, true)) {
                throw new ConfigurationException(
                    "'{$key}' is fixed when a payment link is created and cannot be updated. Create a new link instead."
                );
            }
            if (!in_array($key, CreatePaymentLinkRequest::FIELDS, true)) {
                throw new ConfigurationException(
                    "Unknown payment link update field '{$key}'. The gateway rejects unknown fields with 400."
                );
            }
            if (in_array($key, CreatePaymentLinkRequest::TIME_FIELDS, true)) {
                $value = CreatePaymentLinkRequest::isoTime($value, $key);
            } elseif ($key === 'items' && is_array($value)) {
                $value = array_map(static fn ($item) => $item instanceof PaymentLinkItem ? $item->toArray() : $item, array_values($value));
            } elseif ($key === 'customFields' && is_array($value)) {
                $value = array_map(static fn ($field) => $field instanceof PaymentLinkCustomField ? $field->toArray() : $field, array_values($value));
            }
            $body[$key] = $value;
        }

        return $body;
    }

    /**
     * Object fields always go out as JSON objects. PHP encodes an array with
     * keys 0..n-1 as a JSON list — `[]` when empty, and `["x"]` for metadata
     * like `['0' => 'x']` — which the gateway rejects for an object field.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private static function encodeObjects(array $body): array
    {
        foreach (CreatePaymentLinkRequest::OBJECT_FIELDS as $key) {
            if (isset($body[$key]) && is_array($body[$key])) {
                $body[$key] = (object) $body[$key];
            }
        }

        return $body;
    }

    /**
     * @param array<string,mixed> $filters
     * @param string[] $allowed
     * @return array<string,scalar>
     */
    private static function query(array $filters, array $allowed): array
    {
        $query = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $filters)) {
                continue;
            }
            $value = $filters[$key];
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            if (is_array($value)) {
                $value = implode(',', array_map('strval', $value));
            } elseif ($value instanceof DateTimeInterface) {
                $value = (string) CreatePaymentLinkRequest::isoTime($value, $key);
            } elseif (is_bool($value)) {
                // `true`/`false`, not PHP's `1`/``: the gateway parses the literal words.
                $value = $value ? 'true' : 'false';
            } elseif (!is_scalar($value)) {
                throw new ConfigurationException("Filter '{$key}' must be a scalar, a list or a DateTimeInterface.");
            }
            $query[$key] = $value;
        }

        return $query;
    }

    private static function linkPath(string $linkId): string
    {
        return self::BASE . '/' . rawurlencode(self::requireId($linkId, 'linkId'));
    }

    private static function requireId(string $value, string $field): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw new ConfigurationException("'{$field}' is required.");
        }

        return $trimmed;
    }

    /**
     * @return array{fileName:string, contentType:string, sizeBytes:int}
     */
    private static function imageDeclaration(string $fileName, string $contentType, int $sizeBytes): array
    {
        $fileName = self::requireId($fileName, 'fileName');
        if (!in_array($contentType, self::IMAGE_CONTENT_TYPES, true)) {
            throw new ConfigurationException(
                "Image contentType must be one of " . implode(', ', self::IMAGE_CONTENT_TYPES) . " (SVG is not accepted); got '{$contentType}'."
            );
        }
        if ($sizeBytes < 1) {
            throw new ConfigurationException("Image sizeBytes must be the file size in bytes (a positive integer); got {$sizeBytes}.");
        }

        return ['fileName' => $fileName, 'contentType' => $contentType, 'sizeBytes' => $sizeBytes];
    }

    private static function requireAssetId(string $assetId): string
    {
        $trimmed = trim($assetId);
        if (preg_match(CreatePaymentLinkRequest::ASSET_ID_PATTERN, $trimmed) !== 1) {
            throw new ConfigurationException("'assetId' must be the image's asset id (uuid); got '{$assetId}'.");
        }

        return $trimmed;
    }

    /**
     * An upload target is used only if the SDK can PUT to it as the contract
     * describes: an asset id, an http(s) URL, the PUT method and header
     * strings. Anything else fails before a byte is sent.
     *
     * @param array<string,mixed> $response
     * @return array{assetId:string, uploadUrl:string, uploadMethod:string, uploadHeaders:array<string,string>, expiresAt:?string}
     * @throws ApiException
     */
    private static function uploadTarget(array $response): array
    {
        $target = self::dataOf($response);
        $assetId = $target['assetId'] ?? null;
        $uploadUrl = $target['uploadUrl'] ?? null;
        $headers = $target['uploadHeaders'] ?? null;
        $usable = is_string($assetId) && preg_match(CreatePaymentLinkRequest::ASSET_ID_PATTERN, $assetId) === 1
            && is_string($uploadUrl) && self::isUploadUrl($uploadUrl)
            && ($target['uploadMethod'] ?? null) === 'PUT'
            && is_array($headers) && self::isHeaderMap($headers);
        if (!$usable) {
            throw new ApiException('Image upload creation did not return a usable upload target.', 0, null, $response);
        }

        return [
            'assetId' => $assetId,
            'uploadUrl' => $uploadUrl,
            'uploadMethod' => 'PUT',
            'uploadHeaders' => $headers,
            'expiresAt' => isset($target['expiresAt']) && is_string($target['expiresAt']) ? $target['expiresAt'] : null,
        ];
    }

    private static function isUploadUrl(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($scheme) && in_array(strtolower($scheme), self::UPLOAD_URL_SCHEMES, true)
            && is_string($host) && $host !== '';
    }

    /** @param array<mixed> $headers */
    private static function isHeaderMap(array $headers): bool
    {
        foreach ($headers as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                return false;
            }
        }

        return true;
    }

    /** S3-compatible storage answers errors as XML: `<Error><Code>AccessDenied</Code>…`. */
    private static function storageErrorCode(string $body): ?string
    {
        return preg_match('/<Code>([A-Za-z0-9.]{1,64})<\/Code>/', $body, $match) === 1 ? $match[1] : null;
    }

    private static function requireIdempotencyKey(string $operation, string $key): string
    {
        $trimmed = trim($key);
        if ($trimmed === '') {
            throw new ConfigurationException(sprintf(
                'A stable Idempotency-Key is required for %s. Reuse the same key when retrying the same operation.',
                $operation
            ));
        }

        return self::assertIdempotencyKeyFormat($trimmed);
    }

    private static function optionalIdempotencyKey(?string $key): ?string
    {
        if ($key === null || trim($key) === '') {
            return null;
        }

        return self::assertIdempotencyKeyFormat(trim($key));
    }

    private static function assertIdempotencyKeyFormat(string $key): string
    {
        if (preg_match(self::IDEMPOTENCY_KEY_PATTERN, $key) !== 1) {
            throw new ConfigurationException('Idempotency-Key must be 8-200 printable ASCII characters without spaces.');
        }

        return $key;
    }

    /**
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    private static function dataOf(array $response): array
    {
        return isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
    }
}
