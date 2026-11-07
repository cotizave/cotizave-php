<?php

declare(strict_types=1);

namespace Cotizave;

use Cotizave\Exception\CotizaveException;

/**
 * What a custom `shouldRetry` callback receives to make its decision.
 */
final class RetryContext
{
    public function __construct(
        /** The error of the failed attempt. */
        public readonly CotizaveException $error,
        /** Zero-based index of the attempt that just failed. */
        public readonly int $attempt,
        public readonly string $method,
        /** Request path, e.g. /v2/fx/ar/rates. */
        public readonly string $path,
        /** What the built-in policy would decide for this failure. */
        public readonly bool $defaultDecision,
    ) {
    }
}
