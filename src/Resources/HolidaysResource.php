<?php

declare(strict_types=1);

namespace Cotizave\Resources;

/**
 * Holiday calendars and business-day math (Basic+ in v2).
 *
 * @phpstan-import-type RequestConfig from Resource
 */
final class HolidaysResource extends Resource
{
    /**
     * Holidays for a year (defaults to the current year server-side).
     *
     * @param array{year?: int} $options
     * @param RequestConfig $config
     * @return array<string, mixed>
     */
    public function list(string $country, array $options = [], array $config = []): array
    {
        /** @var array<string, mixed> */
        return $this->transport->get('/v2/holidays/' . self::cc($country), ['year' => $options['year'] ?? null], $config);
    }

    /**
     * Whether a given date is a holiday.
     *
     * @param RequestConfig $config
     * @return array<string, mixed>
     */
    public function check(string $country, string $date, array $config = []): array
    {
        /** @var array<string, mixed> */
        return $this->transport->get('/v2/holidays/' . self::cc($country), ['date' => $date], $config);
    }

    /**
     * The next holiday on or after $from.
     *
     * @param RequestConfig $config
     * @return array<string, mixed>
     */
    public function next(string $country, string $from, array $config = []): array
    {
        /** @var array<string, mixed> */
        return $this->transport->get('/v2/holidays/' . self::cc($country), ['from' => $from], $config);
    }

    /**
     * The date reached by adding $days business days to $from.
     *
     * @param array{days: int, from?: string} $options
     * @param RequestConfig $config
     * @return array<string, mixed>
     */
    public function businessDays(string $country, array $options, array $config = []): array
    {
        $days = $options['days'] ?? null;
        if (!is_int($days) || $days < 0) {
            throw new \InvalidArgumentException('businessDays: days must be a non-negative integer.');
        }

        /** @var array<string, mixed> */
        return $this->transport->get(
            '/v2/holidays/' . self::cc($country) . '/business-days',
            ['from' => $options['from'] ?? null, 'days' => $days],
            $config,
        );
    }
}
