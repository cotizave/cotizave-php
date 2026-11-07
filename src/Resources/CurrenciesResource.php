<?php

declare(strict_types=1);

namespace Cotizave\Resources;

/**
 * Currency catalog (Pro+).
 *
 * @phpstan-import-type RequestConfig from Resource
 */
final class CurrenciesResource extends Resource
{
    /**
     * List currencies Cotizave quotes ({ "currencies": [...] }).
     *
     * @param RequestConfig $config
     * @return array<string, mixed>
     */
    public function list(array $config = []): array
    {
        /** @var array<string, mixed> */
        return $this->transport->get('/v2/currencies', [], $config);
    }

    /**
     * One currency by code (e.g. USD).
     *
     * @param RequestConfig $config
     * @return array<string, mixed>
     */
    public function get(string $code, array $config = []): array
    {
        /** @var array<string, mixed> */
        return $this->transport->get('/v2/currencies/' . self::seg($code, 'currency code'), [], $config);
    }
}
