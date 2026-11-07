<?php

declare(strict_types=1);

namespace Cotizave\Resources;

/**
 * Country catalog (Pro+).
 *
 * @phpstan-import-type RequestConfig from Resource
 */
final class CountriesResource extends Resource
{
    /**
     * List published countries ({ "countries": [...] }).
     *
     * @param RequestConfig $config
     * @return array<string, mixed>
     */
    public function list(array $config = []): array
    {
        /** @var array<string, mixed> */
        return $this->transport->get('/v2/countries', [], $config);
    }

    /**
     * One published country by code.
     *
     * @param RequestConfig $config
     * @return array<string, mixed>
     */
    public function get(string $country, array $config = []): array
    {
        /** @var array<string, mixed> */
        return $this->transport->get('/v2/countries/' . self::cc($country), [], $config);
    }
}
