<?php

declare(strict_types=1);

namespace Cotizave\Exception;

/** 429 — rate limited. Use scope() to tell quota from burst. */
class RateLimitException extends CotizaveException
{
    /** "monthly" = quota exhausted (do not retry soon); "burst" = slow down. */
    public function scope(): ?string
    {
        $fromDetails = $this->details['kind'] ?? null;
        if ($fromDetails === 'monthly' || $fromDetails === 'burst') {
            return $fromDetails;
        }

        return $this->meta?->rateLimit->scope;
    }

    /** Retry-After in seconds, if provided. */
    public function retryAfter(): ?int
    {
        return $this->meta?->rateLimit->retryAfter;
    }

    /** True when the monthly quota is exhausted (waiting a few seconds won't help). */
    public function isQuotaExhausted(): bool
    {
        return $this->scope() === 'monthly';
    }
}
