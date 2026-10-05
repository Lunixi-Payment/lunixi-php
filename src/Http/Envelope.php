<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Http;

/**
 * Gateway yanıt zarfının TEK okuma noktası.
 *
 * Gateway her başarılı yanıtı şu şekilde sarar:
 *
 *     { "status":"success", "code":"SUCCESS", "data": <yük>, "requestId":"..." }
 *
 * ─── 🔴 NEDEN BU SINIF VAR ──────────────────────────────────────────────────
 * `dataOf()` mantığı SDK içinde BEŞ ayrı client'a kopyalanmıştı ve liste
 * metotları o kopyayı KULLANMIYORDU. Sonuç, canlı iki kırık metottu:
 *
 *   · `KycClient::listSessions()`  → `$response['data']` bir DİZİ sanılıyordu,
 *     oysa `{items, nextCursor}` NESNESİ. Her çağrı çöp `KycSession` üretiyor,
 *     `hasMore()` daima `false` dönüyordu.
 *   · `MarketplaceClient::listDealers()` → aynı varsayım; daima BOŞ dizi.
 *     WordPress eklentisinin bayi ekranı bu yüzden boştu.
 *
 * Zarf okumak tek satırlık bir iş ama YANLIŞ okumak sessizdir: hata değil, boş
 * liste döner. Bu yüzden tek yerde toplandı ve testle kilitlendi.
 *
 * ─── LİSTE YÜKÜ ŞEKİLLERİ ───────────────────────────────────────────────────
 * Platform sayfalama standardı `{items, pageInfo:{hasMore,nextCursor,totalCount}}`
 * üretir. Geçiş sürecinde eski şekiller de dolaşıyor ve HEPSİ desteklenmelidir:
 *
 *     { items: [...], pageInfo: {...} }        ← kanonik
 *     { items: [...], nextCursor: "..." }      ← wallet / kyc
 *     { items: [...], nextPageToken: "..." }   ← subscription (AIP-158)
 *     { operations: [...], nextCursor: "..." } ← wallet (koleksiyon adı farklı)
 *     { rows: [...], nextBeforeSeq: "..." }    ← ledger
 *     { items: [...], total: 42 }              ← automation / marketplace
 *     [ ... ]                                  ← çıplak dizi (sayfalanmayan uçlar)
 */
final class Envelope
{
    /**
     * Zarfı açar. Zarf yoksa gövdeyi aynen döndürür (düz yanıtlarla uyumlu).
     *
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    public static function data(array $response): array
    {
        return isset($response['data']) && is_array($response['data'])
            ? $response['data']
            : $response;
    }

    /**
     * Bir liste yanıtından SATIRLARI çıkarır.
     *
     * Sırayla dener: zarfın içindeki bilinen koleksiyon anahtarları → yükün
     * kendisi bir liste ise o. Hiçbiri tutmazsa BOŞ dizi (istisna değil: boş
     * liste geçerli bir yanıttır).
     *
     * @param array<string,mixed> $response
     * @return array<int,array<string,mixed>>
     */
    public static function items(array $response): array
    {
        $payload = self::data($response);

        foreach (['items', 'operations', 'rows'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return array_values(array_filter(
                    $payload[$key],
                    static fn ($row): bool => is_array($row)
                ));
            }
        }

        // Çıplak dizi yükü (sayfalanmayan uçlar) — `data` doğrudan liste.
        if (self::isList($payload)) {
            return array_values(array_filter($payload, static fn ($row): bool => is_array($row)));
        }

        return [];
    }

    /**
     * `array_is_list()` eşdeğeri.
     *
     * ⚠️ Yerleşik `array_is_list()` PHP 8.1+; bu paket `composer.json`'da
     * `php: >=7.4` ilan ediyor. Yerleşiği kullanmak 7.4/8.0 kuran entegratörde
     * FATAL ERROR üretirdi.
     *
     * @param array<mixed> $value
     */
    private static function isList(array $value): bool
    {
        $expected = 0;
        foreach ($value as $key => $_) {
            if ($key !== $expected++) {
                return false;
            }
        }

        return true;
    }

    /**
     * Bir sonraki sayfanın OPAK imleci; son sayfada `null`.
     *
     * ⚠️ İmleç opaktır: çözmeyin, üretmeyin, değiştirmeyin. Yalnız bir sonraki
     * istekte aynen geri gönderin. Bozuk imleç gateway'de `400 INVALID_CURSOR`
     * döner (sessizce ilk sayfaya DÖNMEZ).
     *
     * @param array<string,mixed> $response
     */
    public static function nextCursor(array $response): ?string
    {
        $payload = self::data($response);

        $pageInfo = $payload['pageInfo'] ?? null;
        if (is_array($pageInfo)) {
            $value = $pageInfo['nextCursor'] ?? $pageInfo['endCursor'] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
            // `pageInfo` VAR ama imleç boş → son sayfa. Eski alanlara düşme.
            if (array_key_exists('nextCursor', $pageInfo) || array_key_exists('endCursor', $pageInfo)) {
                return null;
            }
        }

        foreach (['nextCursor', 'nextPageToken', 'nextBeforeSeq'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Filtreye uyan TOPLAM satır sayısı — yalnız sunucu gerçekten döndürdüyse.
     *
     * Cursor sayfalamada `totalCount` VARSAYILAN OLARAK `null`'dır (filtreli
     * `COUNT(*)` pahalıdır). İlerlemek için `nextCursor()` kullanın; toplam
     * gerçekten gerekiyorsa isteğe `includeTotal=true` ekleyin.
     *
     * @param array<string,mixed> $response
     */
    public static function totalCount(array $response): ?int
    {
        $payload = self::data($response);

        $pageInfo = $payload['pageInfo'] ?? null;
        if (is_array($pageInfo) && isset($pageInfo['totalCount']) && is_int($pageInfo['totalCount'])) {
            return $pageInfo['totalCount'];
        }

        foreach (['totalCount', 'total'] as $key) {
            if (isset($payload[$key]) && is_int($payload[$key])) {
                return $payload[$key];
            }
        }

        return null;
    }
}
