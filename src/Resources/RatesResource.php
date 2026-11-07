<?php

declare(strict_types=1);

namespace Cotizave\Resources;

/**
 * Exchange-rate reads: per-country list, single rate by id, and the global feed.
 *
 * @phpstan-import-type RequestConfig from Resource
 */
final class RatesResource extends Resource
{
    /**
     * List every rate published for a country.
     *
     * @param array{tag?: string, category?: string, base?: string, quote?: string, markup?: string} $options
     * @param RequestConfig $config
     * @return array<string, mixed>
     */
    public function list(string $country, array $options = [], array $config = []): array
    {
        /** @var array<string, mixed> */
        return $this->transport->get('/v2/fx/' . self::cc($country) . '/rates', [
            'tag' => $options['tag'] ?? null,
            'category' => $options['category'] ?? null,
            'base' => $options['base'] ?? null,
            'quote' => $options['quote'] ?? null,
            'markup' => $options['markup'] ?? null,
        ], $config);
    }

    /**
     * Fetch one rate by its canonical id.
     *
     * @param RequestConfig $config
     * @return array<string, mixed>
     */
    public function get(string $country, string $rateId, array $config = []): array
    {
        /** @var array<string, mixed> */
        return $this->transport->get(
            '/v2/fx/' . self::cc($country) . '/rates/' . self::seg($rateId, 'rate id'),
            [],
            $config,
        );
    }

    /**
     * The full board of a country as it closed on a past day (subject to retention).
     *
     * @param RequestConfig $config
     * @return array<string, mixed>
     */
    public function snapshot(string $country, string $date, array $config = []): array
    {
        /** @var array<string, mixed> */
        return $this->transport->get(
            '/v2/fx/' . self::cc($country) . '/rates',
            ['date' => self::seg($date, 'date')],
            $this->historyConfig($config),
        );
    }

    /**
     * The official rate of every published country in a single call.
     *
     * @param array{symbols?: list<string>|string, base?: string} $options
     * @param RequestConfig $config
     * @return array<string, mixed>
     */
    public function global(array $options = [], array $config = []): array
    {
        $symbols = $options['symbols'] ?? null;
        /** @var array<string, mixed> */
        return $this->transport->get('/v2/fx/rates', [
            'symbols' => is_array($symbols) ? implode(',', $symbols) : $symbols,
            'base' => $options['base'] ?? null,
        ], $config);
    }
}
