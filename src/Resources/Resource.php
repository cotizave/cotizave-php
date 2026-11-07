<?php

declare(strict_types=1);

namespace Cotizave\Resources;

use Cotizave\Transport;

/**
 * Shared base for resource groups: holds the transport and small helpers.
 *
 * @internal
 *
 * @phpstan-type RequestConfig array{timeout?: float|int, maxRetries?: int, headers?: array<string, string>, language?: string}
 */
abstract class Resource
{
    public function __construct(protected readonly Transport $transport)
    {
    }

    /** Normalize an ISO-3166 alpha-2 country code (the API is case-insensitive). */
    protected static function cc(string $code): string
    {
        $code = trim($code);
        if ($code === '') {
            throw new \InvalidArgumentException('A country code (ISO 3166 alpha-2, e.g. "AR") is required.');
        }

        return rawurlencode(strtolower($code));
    }

    /** Encode a single path segment (rate id, bank code, currency code). */
    protected static function seg(string $value, string $label): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException("A {$label} is required.");
        }

        return rawurlencode($value);
    }

    /**
     * Per-call config for a history read: the caller's timeout wins, otherwise
     * the (longer) client-wide history timeout applies.
     *
     * @param RequestConfig $config
     * @return RequestConfig
     */
    protected function historyConfig(array $config): array
    {
        if (!isset($config['timeout'])) {
            $config['timeout'] = $this->transport->historyTimeout();
        }

        return $config;
    }
}
