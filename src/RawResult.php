<?php

declare(strict_types=1);

namespace Cotizave;

/**
 * Low-level result returned by Cotizave::request().
 */
final class RawResult
{
    public function __construct(
        /** Parsed body, or null on a 304 Not Modified / empty body. */
        public readonly mixed $data,
        /** Status, headers and parsed rate-limit metadata. */
        public readonly ResponseMeta $meta,
        /** True when the server answered 304 Not Modified. */
        public readonly bool $notModified,
    ) {
    }
}
