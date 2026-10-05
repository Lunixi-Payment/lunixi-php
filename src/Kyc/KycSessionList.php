<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Kyc;

use Lunixi\Sdk\Http\Envelope;

/**
 * A cursor-paginated list of KYC sessions (GET /api/v1/kyc/sessions).
 */
final class KycSessionList
{
    /** @var KycSession[] */
    private array $items;
    private ?string $nextPageToken;

    /**
     * @param array<string,mixed> $response The full list envelope.
     *
     * 🔴 DÜZELTİLEN DEFEKT: eskiden `$response['data']` bir SESSION DİZİSİ
     * sanılıyordu. Gerçekte gateway `{status,code,data:{items,nextCursor}}`
     * döndürüyor — yani `data` bir NESNE. `array_values()` onu
     * `[items_dizisi, nextCursor_metni]`'ne çeviriyor ve iki çöp `KycSession`
     * üretiyordu; `nextPageToken` de üst seviyede aranıp DAİMA `null` kalıyor,
     * `hasMore()` her zaman `false` dönüyordu. Yani sayfalama HİÇ çalışmıyordu.
     *
     * Zarf okuma artık tek yerden: {@see Envelope}.
     */
    public function __construct(array $response)
    {
        $this->items = array_map(
            static fn (array $row): KycSession => new KycSession($row),
            Envelope::items($response)
        );
        $this->nextPageToken = Envelope::nextCursor($response);
    }

    /** @return KycSession[] */
    public function items(): array
    {
        return $this->items;
    }

    public function nextPageToken(): ?string
    {
        return $this->nextPageToken;
    }

    public function hasMore(): bool
    {
        return $this->nextPageToken !== null;
    }
}
