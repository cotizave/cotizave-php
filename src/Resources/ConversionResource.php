<?php

declare(strict_types=1);

namespace Cotizave\Resources;

/**
 * Conversion operations. The arithmetic runs on Cotizave, never in the SDK.
 *
 * @phpstan-import-type RequestConfig from Resource
 */
final class ConversionResource extends Resource
{
    /** The maximum number of conversions accepted by a single bulk request. */
    public const int BULK_CONVERT_MAX = 50;

    /**
     * Convert one amount between two currencies.
     *
     * @param array{from: string, to: string, amount: float|int, via?: string, market?: string, date?: string} $options
     * @param RequestConfig $config
     * @return array<string, mixed>
     */
    public function convert(array $options, array $config = []): array
    {
        $amount = $options['amount'] ?? null;
        if (!is_int($amount) && !is_float($amount)) {
            throw new \InvalidArgumentException('convert: amount must be a number greater than 0.');
        }
        if ($amount <= 0) {
            throw new \InvalidArgumentException('convert: amount must be a number greater than 0.');
        }

        /** @var array<string, mixed> */
        return $this->transport->get('/v2/fx/convert', [
            'from' => $options['from'] ?? null,
            'to' => $options['to'] ?? null,
            'amount' => $amount,
            'via' => $options['via'] ?? null,
            'market' => $options['market'] ?? null,
            'date' => $options['date'] ?? null,
        ], $config);
    }

    /**
     * Convert up to 50 items in one request (Pro+). Counts as a single query.
     * Each item keeps its input order and carries either a conversion or an error.
     *
     * @param list<array{from: string, to: string, amount: float|int, via?: string, date?: string}> $conversions
     * @param RequestConfig $config
     * @return array<string, mixed>
     */
    public function convertBulk(array $conversions, array $config = []): array
    {
        if ($conversions === []) {
            throw new \InvalidArgumentException('convertBulk: conversions must be a non-empty list.');
        }
        if (count($conversions) > self::BULK_CONVERT_MAX) {
            throw new \InvalidArgumentException(
                'convertBulk: at most ' . self::BULK_CONVERT_MAX . ' conversions are allowed (got ' . count($conversions) . ').'
            );
        }

        /** @var array<string, mixed> */
        return $this->transport->post('/v2/fx/convert', ['conversions' => array_values($conversions)], $config);
    }
}
