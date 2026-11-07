<?php

declare(strict_types=1);

namespace Cotizave;

/**
 * Everything the SDK knows about a finished HTTP exchange.
 */
final class ResponseMeta
{
    /**
     * @param array<string, string> $headers lowercase-keyed response headers
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly RateLimitInfo $rateLimit,
        public readonly ?string $requestId = null,
    ) {
    }
}
