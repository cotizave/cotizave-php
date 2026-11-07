<?php

declare(strict_types=1);

namespace Cotizave\Resources;

/**
 * Advanced per-country FX tools: catalog, spread, best route, bank rates.
 *
 * @phpstan-import-type RequestConfig from Resource
 */
final class FxToolsResource extends Resource
{
    /**
     * The country's rate catalog (no prices). Growth+.
     *
     * @param RequestConfig $config
     * @return array<string, mixed>
     */
    public function markets(string $country, array $config = []): array
    {
        /** @var array<string, mixed> */
        return $this->transport->get('/v2/fx/' . self::cc($country) . '/markets', [], $config);
    }

    /**
     * The spread of the official rate against another market. Growth+.
     *
     * @param array{against?: string, base?: string} $options
     * @param RequestConfig $config
     * @return array<string, mixed>
     */
    public function spread(string $country, array $options = [], array $config = []): array
    {
        /** @var array<string, mixed> */
        return $this->transport->get('/v2/fx/' . self::cc($country) . '/spread', [
            'against' => $options['against'] ?? null,
            'base' => $options['base'] ?? null,
        ], $config);
    }

    /**
     * The best executable rate for an amount. Pro+. The backend resolves the
     * route — the SDK never computes it.
     *
     * @param array{from: string, to: string, amount: float|int, direction?: string, maxStaleness?: int} $options
     * @param RequestConfig $config
     * @return array<string, mixed>
     */
    public function route(string $country, array $options, array $config = []): array
    {
        /** @var array<string, mixed> */
        return $this->transport->get('/v2/fx/' . self::cc($country) . '/route', [
            'from' => $options['from'] ?? null,
            'to' => $options['to'] ?? null,
            'amount' => $options['amount'] ?? null,
            'direction' => $options['direction'] ?? null,
            'max_staleness' => $options['maxStaleness'] ?? null,
        ], $config);
    }

    /**
     * BCV per-bank quotations (all plans). Optionally filtered by bank code.
     *
     * @param array{bank?: string} $options
     * @param RequestConfig $config
     * @return array<string, mixed>
     */
    public function bankRates(string $country, array $options = [], array $config = []): array
    {
        /** @var array<string, mixed> */
        return $this->transport->get('/v2/fx/' . self::cc($country) . '/bank-rates', [
            'bank' => $options['bank'] ?? null,
        ], $config);
    }
}
