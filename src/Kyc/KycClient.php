<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Kyc;

use Lunixi\Sdk\ApiClient;

/**
 * Server-side KYC/KYB/evidence operations against the gateway (`/api/v1/kyc/*`).
 * Bearer auth only (no Ed25519 step-up).
 *
 *   createSession  POST /kyc/sessions
 *   getSession     GET  /kyc/sessions/{id}
 *   listSessions   GET  /kyc/sessions
 *   submit         POST /kyc/sessions/{id}/submit
 *   manualReview   POST /kyc/sessions/{id}/manual-review
 *   evidenceRequest POST /kyc/evidence-requests
 *
 * The WP BFF calls createSession (externalCustomerId = WP user id); the browser
 * then bootstraps the SDK token directly against the gateway's publishable-key
 * `/kyc/sdk/init` (that endpoint is no longer bearer/server-reachable, so there
 * is no sdkInit method here). The kyc.* webhook is the authority for the
 * resulting verification state.
 */
final class KycClient
{
    private const BASE = '/api/v1/kyc';

    private ApiClient $api;

    public function __construct(ApiClient $api)
    {
        $this->api = $api;
    }

    public function createSession(CreateSessionRequest $request): KycSession
    {
        $response = $this->api->request('POST', self::BASE . '/sessions', $request->toArray(), [
            'idempotencyKey' => $request->idempotencyKey(),
        ]);

        return new KycSession(self::dataOf($response));
    }

    public function getSession(string $sessionId): KycSession
    {
        $response = $this->api->request('GET', self::BASE . '/sessions/' . rawurlencode($sessionId));

        return new KycSession(self::dataOf($response));
    }

    /**
     * Oturum listesi — cursor sayfalaması.
     *
     * 🔴 DÜZELTİLEN DEFEKT: bu metot `externalCustomerId`/`status`/`sessionType`/
     * `pageSize`/`pageToken` gönderiyordu. `GET /api/v1/kyc/sessions` bunların
     * HİÇBİRİNİ kabul etmiyor (yalnız `limit` + `cursor`) ve gateway
     * `forbidNonWhitelisted` ile koştuğu için bilinmeyen alan **400** üretiyordu.
     * Yani filtreli her çağrı hata alıyordu.
     *
     * `pageSize`/`pageToken` GERİYE UYUMLU olarak kabul edilmeye devam eder ve
     * tel üzerinde `limit`/`cursor`'a çevrilir — bu adları kullanan mevcut
     * entegratör kırılmaz.
     *
     * ⚠️ Desteklenmeyen filtre SESSİZCE DÜŞÜRÜLMEZ, istisna fırlatılır: bir
     * `status` filtresini yok saymak çağırana TÜM oturumları "filtrelenmiş"
     * diye döndürürdü — 400'den daha tehlikelidir. (Merchant yüzeyinde bu
     * filtreler backend'de de yok; yalnız admin yolunda var.)
     *
     * @param array{limit?:int, cursor?:string, pageSize?:int, pageToken?:string} $filters
     *
     * @throws \InvalidArgumentException Desteklenmeyen bir filtre verildiğinde.
     */
    public function listSessions(array $filters = []): KycSessionList
    {
        $unsupported = array_diff(
            array_keys($filters),
            ['limit', 'cursor', 'pageSize', 'pageToken']
        );
        if ($unsupported !== []) {
            throw new \InvalidArgumentException(sprintf(
                'GET /api/v1/kyc/sessions su filtreleri desteklemiyor: %s. Desteklenen: limit, cursor.',
                implode(', ', $unsupported)
            ));
        }

        $query = [];
        $limit = $filters['limit'] ?? $filters['pageSize'] ?? null;
        if ($limit !== null && $limit !== '') {
            $query['limit'] = (int) $limit;
        }
        $cursor = $filters['cursor'] ?? $filters['pageToken'] ?? null;
        if (is_string($cursor) && $cursor !== '') {
            $query['cursor'] = $cursor;
        }

        $response = $this->api->request('GET', self::BASE . '/sessions', null, ['query' => $query]);

        return new KycSessionList($response);
    }

    /** Sets the applicant jurisdiction (ISO country) on a server-created session. */
    public function setJurisdiction(string $sessionId, string $jurisdiction): KycSession
    {
        $response = $this->api->request('POST', self::BASE . '/sessions/' . rawurlencode($sessionId) . '/jurisdiction', ['jurisdiction' => $jurisdiction]);

        return new KycSession(self::dataOf($response));
    }

    /**
     * Selects the applicant's primary ID document type.
     *
     * @param string $documentType PASSPORT | NATIONAL_ID | DRIVERS_LICENSE | RESIDENCE_PERMIT
     */
    public function setPrimaryDocument(string $sessionId, string $documentType): KycSession
    {
        $response = $this->api->request('POST', self::BASE . '/sessions/' . rawurlencode($sessionId) . '/primary-document', ['documentType' => $documentType]);

        return new KycSession(self::dataOf($response));
    }

    /**
     * Uploads a document image server-side (most flows use the browser SDK).
     *
     * @param array<string,mixed> $document documentType, side (FRONT|BACK), fileBase64,
     *   mimeType, jurisdiction?, metadataJson?, idempotencyKey?
     */
    public function uploadDocument(string $sessionId, array $document): KycSession
    {
        $response = $this->api->request('POST', self::BASE . '/sessions/' . rawurlencode($sessionId) . '/documents', $document);

        return new KycSession(self::dataOf($response));
    }

    /**
     * Session event timeline (forensics/audit).
     *
     * @return array<string,mixed>
     */
    public function timeline(string $sessionId): array
    {
        return $this->api->request('GET', self::BASE . '/sessions/' . rawurlencode($sessionId) . '/timeline');
    }

    public function submit(string $sessionId): KycSession
    {
        $response = $this->api->request('POST', self::BASE . '/sessions/' . rawurlencode($sessionId) . '/submit');

        return new KycSession(self::dataOf($response));
    }

    public function manualReview(string $sessionId, ?string $reasonCode = null, ?string $reasonDetail = null): KycSession
    {
        $body = [];
        if ($reasonCode !== null && $reasonCode !== '') {
            $body['reasonCode'] = $reasonCode;
        }
        if ($reasonDetail !== null && $reasonDetail !== '') {
            $body['reasonDetail'] = $reasonDetail;
        }
        $response = $this->api->request('POST', self::BASE . '/sessions/' . rawurlencode($sessionId) . '/manual-review', $body !== [] ? $body : null);

        return new KycSession(self::dataOf($response));
    }

    /**
     * Creates an EVIDENCE session (proof-of-X), optionally linked to another
     * object (a payment intent, order, …).
     */
    public function evidenceRequest(CreateSessionRequest $request): KycSession
    {
        $response = $this->api->request('POST', self::BASE . '/evidence-requests', $request->toArray(), [
            'idempotencyKey' => $request->idempotencyKey(),
        ]);

        return new KycSession(self::dataOf($response));
    }

    // ── Org-level config / compliance (bearer + permissions) ─────────────────

    /**
     * Reads the merchant's KYC policy (required assurance, providers, etc.).
     *
     * @return array<string,mixed>
     */
    public function merchantPolicy(): array
    {
        return $this->api->request('GET', self::BASE . '/admin/merchant-policy');
    }

    /**
     * Updates the merchant's KYC policy.
     *
     * @param array<string,mixed> $policy
     * @return array<string,mixed>
     */
    public function updateMerchantPolicy(array $policy): array
    {
        return $this->api->request('POST', self::BASE . '/admin/merchant-policy', $policy);
    }

    /**
     * Rotates the KYC webhook signing secret.
     *
     * @return array<string,mixed>
     */
    public function rotateWebhookSecret(): array
    {
        return $this->api->request('POST', self::BASE . '/admin/merchant-policy/webhook-secret/rotate');
    }

    /**
     * Lists KYC webhook outbox records (delivery audit).
     *
     * @param array{limit?:int, status?:string} $filters
     * @return array<string,mixed>
     */
    public function webhookOutbox(array $filters = []): array
    {
        $query = [];
        foreach (['limit', 'status'] as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $query[$key] = $filters[$key];
            }
        }

        return $this->api->request('GET', self::BASE . '/admin/webhook-outbox', null, ['query' => $query]);
    }

    /**
     * Retries delivery of a KYC webhook event.
     *
     * @return array<string,mixed>
     */
    public function retryWebhookEvent(string $eventId): array
    {
        return $this->api->request('POST', self::BASE . '/admin/webhook-outbox/' . rawurlencode($eventId) . '/retry');
    }

    /**
     * Lists GDPR/KVKK erasure requests.
     *
     * @param array{limit?:int, status?:string} $filters
     * @return array<string,mixed>
     */
    public function erasureRequests(array $filters = []): array
    {
        $query = [];
        foreach (['limit', 'status'] as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $query[$key] = $filters[$key];
            }
        }

        return $this->api->request('GET', self::BASE . '/admin/erasure-requests', null, ['query' => $query]);
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
