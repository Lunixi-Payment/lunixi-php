<?php

declare(strict_types=1);

namespace Lunixi\Sdk\Reference;

use Lunixi\Sdk\ApiClient;
use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\Http\Envelope;

/**
 * Lunixi address dictionary for Türkiye (province → district → neighborhood).
 * The codes fill an ADDRESS input field, e.g. the debtor address of a Senetle
 * Ödeme payment: `['provinceCode' => …, 'districtCode' => …, 'neighborhoodCode' => …, 'line' => …]`.
 *
 *   listProvinces      GET /api/v1/reference/tr-address/provinces
 *   listDistricts      GET /api/v1/reference/tr-address/districts?provinceCode=06
 *   listNeighborhoods  GET /api/v1/reference/tr-address/neighborhoods?districtCode=…
 *
 * Public reference data (no personal data). Each call returns the rows
 * `[['code' => …, 'name' => …], …]`. An empty list means the data for that
 * level is not published yet; show that state instead of inventing codes.
 */
final class ReferenceClient
{
    private const BASE = '/api/v1/reference/tr-address';
    private const PROVINCE_CODE_PATTERN = '/^(0[1-9]|[1-7][0-9]|8[01])$/';
    private const DISTRICT_CODE_PATTERN = '/^[0-9]{1,12}$/';

    private ApiClient $api;

    public function __construct(ApiClient $api)
    {
        $this->api = $api;
    }

    /**
     * Provinces with their Lunixi code (`01`–`81`).
     *
     * @return list<array{code:string,name:string}>
     * @throws ApiException
     */
    public function listProvinces(): array
    {
        return self::rows($this->api->request('GET', self::BASE . '/provinces'));
    }

    /**
     * Districts of a province (`$provinceCode` `01`–`81`).
     *
     * @return list<array{code:string,name:string}>
     * @throws ApiException
     */
    public function listDistricts(string $provinceCode): array
    {
        if (preg_match(self::PROVINCE_CODE_PATTERN, $provinceCode) !== 1) {
            throw new ConfigurationException(sprintf('provinceCode must be a two-digit province code from 01 to 81; got "%s".', $provinceCode));
        }

        return self::rows($this->api->request('GET', self::BASE . '/districts', null, ['query' => ['provinceCode' => $provinceCode]]));
    }

    /**
     * Neighborhoods of a district (`$districtCode` from listDistricts()).
     *
     * @return list<array{code:string,name:string}>
     * @throws ApiException
     */
    public function listNeighborhoods(string $districtCode): array
    {
        if (preg_match(self::DISTRICT_CODE_PATTERN, $districtCode) !== 1) {
            throw new ConfigurationException(sprintf('districtCode must be a district code (digits) from listDistricts(); got "%s".', $districtCode));
        }

        return self::rows($this->api->request('GET', self::BASE . '/neighborhoods', null, ['query' => ['districtCode' => $districtCode]]));
    }

    /**
     * @param array<string,mixed> $response
     * @return list<array{code:string,name:string}>
     */
    private static function rows(array $response): array
    {
        $rows = [];
        foreach (Envelope::items($response) as $row) {
            $rows[] = ['code' => (string) ($row['code'] ?? ''), 'name' => (string) ($row['name'] ?? '')];
        }

        return $rows;
    }
}
