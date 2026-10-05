<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Tests\Http;

use Lunixi\Sdk\Http\Envelope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Gateway zarf okuyucusunun sözleşme kilidi.
 *
 * Bu sınıf iki CANLI defektten doğdu: `KycClient::listSessions()` ve
 * `MarketplaceClient::listDealers()` `data`'yı bir SATIR DİZİSİ sanıyordu, oysa
 * o bir sayfalama NESNESİ. İkisi de hata vermeden BOŞ/ÇÖP liste döndürüyordu —
 * sessiz olduğu için kimse fark etmemişti. Aşağıdaki şekiller tel üzerinde
 * gerçekten dolaşan şekillerdir; hepsi desteklenmek zorundadır.
 */
final class EnvelopeTest extends TestCase
{
    public function testUnwrapsSuccessEnvelope(): void
    {
        $this->assertSame(
            ['a' => 1],
            Envelope::data(['status' => 'success', 'code' => 'SUCCESS', 'data' => ['a' => 1]])
        );
    }

    public function testPassesThroughWhenNotEnveloped(): void
    {
        $this->assertSame(['a' => 1], Envelope::data(['a' => 1]));
    }

    /** @return array<string,array{0:array<string,mixed>,1:int,2:?string}> */
    public static function listShapes(): array
    {
        $rows = [['id' => 'a'], ['id' => 'b']];

        return [
            'kanonik items+pageInfo' => [
                ['data' => ['items' => $rows, 'pageInfo' => ['hasMore' => true, 'nextCursor' => 'c1', 'totalCount' => null]]],
                2, 'c1',
            ],
            'items+nextCursor (wallet/kyc)' => [
                ['data' => ['items' => $rows, 'nextCursor' => 'c2']],
                2, 'c2',
            ],
            'items+nextPageToken (subscription AIP-158)' => [
                ['data' => ['items' => $rows, 'nextPageToken' => 'c3', 'pageSize' => 2]],
                2, 'c3',
            ],
            'operations (wallet koleksiyon adi farkli)' => [
                ['data' => ['operations' => $rows, 'nextCursor' => 'c4']],
                2, 'c4',
            ],
            'rows+nextBeforeSeq (ledger)' => [
                ['data' => ['rows' => $rows, 'nextBeforeSeq' => '99']],
                2, '99',
            ],
            'items+total (automation/marketplace, imlec yok)' => [
                ['data' => ['items' => $rows, 'total' => 2]],
                2, null,
            ],
            'ciplak dizi data altinda (sayfalanmayan uclar)' => [
                ['data' => $rows],
                2, null,
            ],
            'zarfsiz ciplak dizi' => [
                $rows,
                2, null,
            ],
            'bos liste' => [
                ['data' => ['items' => [], 'pageInfo' => ['hasMore' => false, 'nextCursor' => null, 'totalCount' => 0]]],
                0, null,
            ],
        ];
    }

    /**
     * `#[DataProvider]` ATTRIBUTE, `@dataProvider` doc-comment DEĞİL: PHPUnit 11
     * doc-comment metadata'yi deprecate etti ve süite uyarı düşürüyordu.
     * (Paket `phpunit ^10.5 || ^11.0` istiyor; attribute 10'dan beri var.)
     *
     * @param array<string,mixed> $response
     */
    #[DataProvider('listShapes')]
    public function testReadsEveryListShape(array $response, int $expectedCount, ?string $expectedCursor): void
    {
        $this->assertCount($expectedCount, Envelope::items($response));
        $this->assertSame($expectedCursor, Envelope::nextCursor($response));
    }

    /**
     * `pageInfo` VARSA ve imleci boşsa SON SAYFADIR — eski alanlara düşülmemeli.
     * Düşülseydi son sayfada bayat bir imleç dönüp istemciyi döngüye sokardı.
     */
    public function testPageInfoWinsOverLegacyCursorFields(): void
    {
        $response = ['data' => [
            'items' => [],
            'pageInfo' => ['hasMore' => false, 'nextCursor' => null, 'totalCount' => null],
            'nextPageToken' => 'BAYAT',
        ]];

        $this->assertNull(Envelope::nextCursor($response));
    }

    public function testTotalCountOnlyWhenServerSentIt(): void
    {
        $this->assertNull(Envelope::totalCount(['data' => ['items' => [], 'pageInfo' => ['totalCount' => null]]]));
        $this->assertSame(42, Envelope::totalCount(['data' => ['items' => [], 'pageInfo' => ['totalCount' => 42]]]));
        $this->assertSame(7, Envelope::totalCount(['data' => ['items' => [], 'total' => 7]]));
        $this->assertNull(Envelope::totalCount(['data' => ['items' => []]]));
    }

    /** Satır olmayan öğeler elenir — çöp `Model` nesnesi üretilmesin. */
    public function testFiltersNonArrayRows(): void
    {
        $items = Envelope::items(['data' => ['items' => [['id' => 'a'], 'coplu', null, 42]]]);
        $this->assertSame([['id' => 'a']], $items);
    }

    public function testUnknownShapeYieldsEmptyListNotError(): void
    {
        $this->assertSame([], Envelope::items(['data' => ['foo' => 'bar']]));
        $this->assertNull(Envelope::nextCursor(['data' => ['foo' => 'bar']]));
    }
}
