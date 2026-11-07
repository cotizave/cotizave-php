<?php

declare(strict_types=1);

namespace Cotizave\Exception;

use Cotizave\RateLimitInfo;
use Cotizave\ResponseMeta;

/**
 * Base class for every error the SDK throws.
 *
 * Catch this to handle any API failure, or a subclass (e.g. RateLimitException)
 * for a specific family. Carries the API-provided code and message when
 * available, plus optional field/details and the response metadata.
 */
class CotizaveException extends \RuntimeException
{
    /** @var array<string, mixed>|null */
    public readonly ?array $details;

    /**
     * @param array<string, mixed>|null $details
     */
    public function __construct(
        string $message,
        /** HTTP status code, or 0 when the request never completed (network/timeout). */
        public readonly int $status = 0,
        /** Machine-readable error code from the API (e.g. rate_limit_exceeded). */
        public readonly string $errorCode = 'cotizave_error',
        /** Offending field, when the API points at one. */
        public readonly ?string $field = null,
        ?array $details = null,
        /** Response metadata, including parsed rate-limit headers. */
        public readonly ?ResponseMeta $meta = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
        $this->details = $details;
    }

    /** Rate-limit metadata parsed from the response, if any. */
    public function rateLimit(): ?RateLimitInfo
    {
        return $this->meta?->rateLimit;
    }

    /**
     * Response headers, if the request completed.
     *
     * @return array<string, string>|null
     */
    public function headers(): ?array
    {
        return $this->meta?->headers;
    }

    /** The server-assigned request id (X-Request-Id), for support. */
    public function requestId(): ?string
    {
        return $this->meta?->requestId;
    }

    /**
     * Build the right exception subclass from a completed HTTP response.
     *
     * @param array<string, mixed>|null $body the already-parsed error payload, or
     *                                         null when the body was absent/not JSON
     */
    public static function fromResponse(
        int $status,
        ?array $body,
        ResponseMeta $meta,
        ?string $rawBodyText = null,
    ): self {
        $class = match ($status) {
            400 => BadRequestException::class,
            401 => AuthenticationException::class,
            402 => FeatureNotInPlanException::class,
            403 => ForbiddenException::class,
            404 => NotFoundException::class,
            406 => NotAcceptableException::class,
            422 => UnprocessableException::class,
            429 => RateLimitException::class,
            default => $status >= 500 ? ServerException::class : self::class,
        };

        $apiCode = (is_array($body) && isset($body['code']) && is_string($body['code'])) ? $body['code'] : null;
        $code = $apiCode ?? self::fallbackCode($status);
        $message = ($apiCode !== null && isset($body['message']) && is_string($body['message']) && $body['message'] !== '')
            ? $body['message']
            : "Unexpected HTTP {$status} response from Cotizave.";

        $details = null;
        if ($apiCode !== null && is_array($body) && isset($body['details']) && is_array($body['details'])) {
            /** @var array<string, mixed> $d */
            $d = $body['details'];
            $details = $d;
        } elseif ($rawBodyText !== null && $rawBodyText !== '') {
            $details = ['non_json_body' => self::truncate($rawBodyText)];
        }

        $field = (is_array($body) && isset($body['field']) && is_string($body['field'])) ? $body['field'] : null;

        return new $class(
            message: $message,
            status: $status,
            errorCode: $code,
            field: $field,
            details: $details,
            meta: $meta,
        );
    }

    private static function fallbackCode(int $status): string
    {
        if ($status >= 500) {
            return 'server_error';
        }
        if ($status === 404) {
            return 'resource_not_found';
        }

        return 'http_error';
    }

    private static function truncate(string $text, int $limit = 500): string
    {
        return mb_strlen($text) <= $limit ? $text : mb_substr($text, 0, $limit) . "\u{2026}";
    }
}
