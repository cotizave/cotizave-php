<?php

declare(strict_types=1);

namespace Cotizave;

use Cotizave\Exception\ConnectionException;
use Cotizave\Exception\CotizaveException;
use Cotizave\Exception\RateLimitException;

/**
 * The retry *policy*: whether a failure is worth retrying and how long to wait.
 * Pure functions; the retry *engine* (the loop and the sleeping) lives in Transport.
 *
 * @internal
 */
final class Retry
{
    /**
     * The built-in decision, before any custom shouldRetry.
     *
     * Only idempotent (GET) requests are retried. A 429 is retried only when it
     * is a burst (velocity) limit, never when the monthly quota is exhausted.
     */
    public static function defaultShouldRetry(CotizaveException $error, string $method, Config $config): bool
    {
        if ($method !== 'GET') {
            return false;
        }
        if ($error instanceof RateLimitException) {
            return $config->retryRateLimited && $error->scope() === 'burst';
        }
        if ($error instanceof ConnectionException) { // network/timeout, no response
            return true;
        }

        return in_array($error->status, $config->retryStatuses, true);
    }

    /** Final decision: the caller's shouldRetry wins when it returns a bool. */
    public static function shouldRetry(
        CotizaveException $error,
        int $attempt,
        string $method,
        string $path,
        Config $config,
    ): bool {
        $default = self::defaultShouldRetry($error, $method, $config);
        if ($config->shouldRetry === null) {
            return $default;
        }
        $custom = ($config->shouldRetry)(new RetryContext($error, $attempt, $method, $path, $default));

        return is_bool($custom) ? $custom : $default;
    }

    /**
     * Seconds to wait before the next attempt, or null to stop retrying.
     *
     * Honors a server Retry-After (seconds) or Cotizave's sub-second
     * X-RateLimit-Retry-After-Ms when present; otherwise exponential backoff
     * with full jitter. Returns null when the wait would exceed the budget.
     */
    public static function nextDelaySeconds(
        CotizaveException $error,
        int $attempt,
        float $budgetLeft,
        Config $config,
    ): ?float {
        if ($budgetLeft <= 0) {
            return null;
        }

        if ($error instanceof RateLimitException && $error->meta !== null) {
            $info = $error->meta->rateLimit;
            $hint = null;
            if ($info->retryAfterMs !== null) {
                $hint = $info->retryAfterMs / 1000.0;
            } elseif ($info->retryAfter !== null) {
                $hint = (float) $info->retryAfter;
            }
            if ($hint !== null) {
                $serverWait = max(0.0, $hint);

                return $serverWait <= $budgetLeft ? $serverWait : null;
            }
        }

        $ceiling = min($config->retryMaxDelay, $config->retryBaseDelay * (2 ** $attempt));
        $jittered = (mt_rand() / mt_getrandmax()) * $ceiling;

        return min($jittered, $budgetLeft);
    }
}
