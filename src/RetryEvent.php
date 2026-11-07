<?php

declare(strict_types=1);

namespace Cotizave;

use Cotizave\Exception\CotizaveException;

/**
 * Passed to the `onRetry` hook right before the SDK waits and retries.
 */
final class RetryEvent
{
    public function __construct(
        public readonly CotizaveException $error,
        /** One-based number of the retry about to happen. */
        public readonly int $retryNumber,
        /** How long the SDK will wait before retrying, in seconds. */
        public readonly float $delay,
        public readonly string $method,
        public readonly string $path,
    ) {
    }
}
