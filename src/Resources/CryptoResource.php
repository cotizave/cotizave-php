<?php

declare(strict_types=1);

namespace Cotizave\Resources;

/**
 * Stablecoin references (USDT, USDC, EURC) priced in USD. Growth+.
 *
 * @phpstan-import-type RequestConfig from Resource
 */
final class CryptoResource extends Resource
{
    /**
     * List the published stablecoins. Optional tag filter (e.g. usdt).
     *
     * @param array{tag?: string} $options
     * @param RequestConfig $config
     * @return array<string, mixed>
     */
    public function list(array $options = [], array $config = []): array
    {
        /** @var array<string, mixed> */
        return $this->transport->get('/v2/crypto', ['tag' => $options['tag'] ?? null], $config);
    }

    /**
     * One stablecoin by its canonical id (e.g. usdt-usd).
     *
     * @param RequestConfig $config
     * @return array<string, mixed>
     */
    public function get(string $cryptoId, array $config = []): array
    {
        /** @var array<string, mixed> */
        return $this->transport->get('/v2/crypto/' . self::seg($cryptoId, 'crypto id'), [], $config);
    }
}
