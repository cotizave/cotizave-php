<?php

declare(strict_types=1);

namespace Cotizave;

/**
 * Parsing of Cotizave's response headers into typed metadata.
 *
 * @internal
 */
final class Headers
{
    /**
     * Flatten PSR-7 headers into a lowercase-keyed, single-value record.
     *
     * @param array<array-key, array<string>> $headers PSR-7 getHeaders() shape
     * @return array<string, string>
     */
    public static function toRecord(array $headers): array
    {
        $record = [];
        foreach ($headers as $name => $values) {
            $record[strtolower((string) $name)] = implode(', ', $values);
        }

        return $record;
    }

    /**
     * @param array<string, string> $record lowercase-keyed headers
     */
    public static function parseRateLimit(array $record): RateLimitInfo
    {
        $scope = $record['x-ratelimit-scope'] ?? null;

        return new RateLimitInfo(
            scope: ($scope === 'monthly' || $scope === 'burst') ? $scope : null,
            limit: self::int($record, 'x-ratelimit-limit'),
            remaining: self::int($record, 'x-ratelimit-remaining'),
            reset: $record['x-ratelimit-reset'] ?? null,
            limitPerMinute: self::int($record, 'x-ratelimit-limit-minute'),
            remainingPerMinute: self::int($record, 'x-ratelimit-remaining-minute'),
            retryAfter: self::int($record, 'retry-after'),
            retryAfterMs: self::int($record, 'x-ratelimit-retry-after-ms'),
        );
    }

    /**
     * @param array<array-key, array<string>> $headers PSR-7 getHeaders() shape
     */
    public static function buildMeta(int $status, array $headers): ResponseMeta
    {
        $record = self::toRecord($headers);
        $requestId = $record['x-request-id'] ?? null;

        return new ResponseMeta(
            status: $status,
            headers: $record,
            rateLimit: self::parseRateLimit($record),
            requestId: ($requestId !== null && $requestId !== '') ? $requestId : null,
        );
    }

    /**
     * @param array<string, string> $record
     */
    private static function int(array $record, string $key): ?int
    {
        $raw = $record[$key] ?? null;
        if ($raw === null || !preg_match('/^-?\d+$/', trim($raw))) {
            return null;
        }

        return (int) trim($raw);
    }
}
