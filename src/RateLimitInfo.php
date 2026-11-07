<?php

declare(strict_types=1);

namespace Cotizave;

/**
 * Parsed rate-limit metadata from response headers.
 */
final class RateLimitInfo
{
    public function __construct(
        /** Which axis was hit on a 429: "monthly" quota vs "burst" (per-minute). */
        public readonly ?string $scope = null,
        /** Monthly quota ceiling (X-RateLimit-Limit). */
        public readonly ?int $limit = null,
        /** Monthly quota remaining (X-RateLimit-Remaining). */
        public readonly ?int $remaining = null,
        /** When the monthly quota resets (X-RateLimit-Reset, RFC 3339). */
        public readonly ?string $reset = null,
        /** Per-minute velocity ceiling (X-RateLimit-Limit-Minute). */
        public readonly ?int $limitPerMinute = null,
        /** Per-minute velocity remaining (X-RateLimit-Remaining-Minute). */
        public readonly ?int $remainingPerMinute = null,
        /** Retry-After in whole seconds. */
        public readonly ?int $retryAfter = null,
        /** Sub-second retry hint for burst limits (X-RateLimit-Retry-After-Ms). */
        public readonly ?int $retryAfterMs = null,
    ) {
    }
}
