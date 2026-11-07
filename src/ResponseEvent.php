<?php

declare(strict_types=1);

namespace Cotizave;

/**
 * Passed to the `onResponse` hook for every completed HTTP exchange.
 */
final class ResponseEvent
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        /** Status, headers and parsed rate-limit metadata. */
        public readonly ResponseMeta $meta,
    ) {
    }
}
