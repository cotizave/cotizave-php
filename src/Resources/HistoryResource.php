<?php

declare(strict_types=1);

namespace Cotizave\Resources;

/**
 * Historical reads: point-in-time, daily series, raw captures, and coverage.
 *
 * @phpstan-import-type RequestConfig from Resource
 */
final class HistoryResource extends Resource
{
    /**
     * The rate that ruled on a specific day.
     *
     * @param RequestConfig $config
     * @return array<string, mixed>
     */
    public function get(string $country, string $rateId, string $date, array $config = []): array
    {
        /** @var array<string, mixed> */
        return $this->transport->get(
            $this->path($country, $rateId),
            ['date' => $date],
            $this->historyConfig($config),
        );
    }

    /**
     * A daily series between from and to.
     *
     * @param array{from?: string, to?: string} $options
     * @param RequestConfig $config
     * @return array<string, mixed>
     */
    public function daily(string $country, string $rateId, array $options = [], array $config = []): array
    {
        /** @var array<string, mixed> */
        return $this->transport->get(
            $this->path($country, $rateId),
            ['granularity' => 'daily', 'from' => $options['from'] ?? null, 'to' => $options['to'] ?? null],
            $this->historyConfig($config),
        );
    }

    /**
     * Aggregates of a date range, computed by Cotizave over the daily closes.
     *
     * @param array{from?: string, to?: string, movingAverage?: int} $options
     * @param RequestConfig $config
     * @return array<string, mixed>
     */
    public function stats(string $country, string $rateId, array $options = [], array $config = []): array
    {
        /** @var array<string, mixed> */
        return $this->transport->get(
            $this->path($country, $rateId),
            [
                'stats' => 1,
                'from' => $options['from'] ?? null,
                'to' => $options['to'] ?? null,
                'ma' => $options['movingAverage'] ?? null,
            ],
            $this->historyConfig($config),
        );
    }

    /**
     * One page of raw (intraday) captures. Pass the previous page's next_cursor.
     *
     * @param array{cursor?: string, limit?: int} $options
     * @param RequestConfig $config
     * @return array<string, mixed>
     */
    public function raw(string $country, string $rateId, array $options = [], array $config = []): array
    {
        /** @var array<string, mixed> */
        return $this->transport->get(
            $this->path($country, $rateId),
            ['granularity' => 'raw', 'cursor' => $options['cursor'] ?? null, 'limit' => $options['limit'] ?? null],
            $this->historyConfig($config),
        );
    }

    /**
     * Iterator over raw captures, following cursors for you.
     *
     * Bounded by maxPages (default 50) so it never silently downloads an entire
     * history. Break out of the loop at any time to stop fetching.
     *
     * @param array{limit?: int, maxPages?: int} $options
     * @param RequestConfig $config
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterate(string $country, string $rateId, array $options = [], array $config = []): \Generator
    {
        $maxPages = $options['maxPages'] ?? 50;
        $cursor = null;
        for ($page = 0; $page < $maxPages; $page++) {
            $pageOptions = [];
            if (isset($options['limit'])) {
                $pageOptions['limit'] = $options['limit'];
            }
            if ($cursor !== null) {
                $pageOptions['cursor'] = $cursor;
            }
            $result = $this->raw($country, $rateId, $pageOptions, $config);
            $points = $result['points'] ?? [];
            if (is_array($points)) {
                foreach ($points as $point) {
                    /** @var array<string, mixed> $point */
                    yield $point;
                }
            }
            $next = $result['next_cursor'] ?? null;
            if (!is_string($next) || $next === '') {
                return;
            }
            $cursor = $next;
        }
    }

    /**
     * Coverage (first/last/days) of the stored history for every rate the plan can see.
     *
     * @param RequestConfig $config
     * @return array<string, mixed>
     */
    public function limits(array $config = []): array
    {
        /** @var array<string, mixed> */
        return $this->transport->get('/v2/fx/historical-limits', [], $this->historyConfig($config));
    }

    private function path(string $country, string $rateId): string
    {
        return '/v2/fx/' . self::cc($country) . '/rates/' . self::seg($rateId, 'rate id') . '/history';
    }
}
