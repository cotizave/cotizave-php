<?php

declare(strict_types=1);

namespace Cotizave;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

/**
 * Client configuration: validation and resolved defaults.
 *
 * Misconfiguration throws \InvalidArgumentException (not a CotizaveException),
 * because no request has been made yet.
 *
 * @phpstan-type Options array{
 *     apiKey?: string,
 *     baseUrl?: string,
 *     timeout?: float|int,
 *     historyTimeout?: float|int,
 *     maxRetries?: int,
 *     maxRetryDelay?: float|int,
 *     maxResponseBytes?: int,
 *     retry?: array{baseDelay?: float|int, maxDelay?: float|int, statuses?: list<int>, retryRateLimited?: bool, shouldRetry?: callable, onRetry?: callable},
 *     headers?: array<string, string>,
 *     language?: string,
 *     userAgent?: string,
 *     allowInsecureHttp?: bool,
 *     onResponse?: callable,
 *     httpClient?: \GuzzleHttp\ClientInterface
 * }
 */
final class Config
{
    public const string DEFAULT_BASE_URL = 'https://api.cotizave.com';
    public const float DEFAULT_TIMEOUT = 5.0;
    public const float DEFAULT_HISTORY_TIMEOUT = 10.0;
    public const int DEFAULT_MAX_RETRIES = 2;
    public const float DEFAULT_MAX_RETRY_DELAY = 10.0;
    public const int DEFAULT_MAX_RESPONSE_BYTES = 25_000_000;
    public const float DEFAULT_RETRY_BASE_DELAY = 0.25;
    public const float DEFAULT_RETRY_MAX_DELAY = 2.0;
    /** @var list<int> */
    public const array DEFAULT_RETRY_STATUSES = [408, 500, 502, 503, 504];
    public const string API_KEY_ENV = 'COTIZAVE_API_KEY';
    public const string SDK_VERSION = '0.1.0';

    private const string RESERVED_HEADER = 'x-api-key';

    /**
     * @param array<string, string> $headers
     * @param list<int>             $retryStatuses
     * @param \Closure|null         $shouldRetry invoked as fn(RetryContext): ?bool
     * @param \Closure|null         $onRetry     invoked as fn(RetryEvent): void
     * @param \Closure|null         $onResponse  invoked as fn(ResponseEvent): void
     */
    private function __construct(
        /**
         * Opaque handle into a private static secret store, NOT the key itself.
         * The key is never an instance property, so it never appears in
         * var_dump()/print_r()/var_export()/json_encode() of the client (static
         * properties are not part of an object dump). Read it with apiKey().
         */
        private readonly string $secretRef,
        public readonly string $baseUrl,
        public readonly float $timeout,
        public readonly float $historyTimeout,
        public readonly int $maxRetries,
        public readonly float $maxRetryDelay,
        public readonly int $maxResponseBytes,
        public readonly float $retryBaseDelay,
        public readonly float $retryMaxDelay,
        public readonly array $retryStatuses,
        public readonly bool $retryRateLimited,
        public readonly ?\Closure $shouldRetry,
        public readonly ?\Closure $onRetry,
        public readonly array $headers,
        public readonly ?string $language,
        public readonly string $userAgent,
        public readonly ?\Closure $onResponse,
        public readonly ClientInterface $httpClient,
    ) {
    }

    /**
     * Process-wide secret store, keyed by the opaque per-config handle.
     *
     * @var array<string, string>
     */
    private static array $secretStore = [];

    /** The resolved API key. Held off the instance so it never leaks into dumps. */
    public function apiKey(): string
    {
        return self::$secretStore[$this->secretRef] ?? '';
    }

    public function __destruct()
    {
        unset(self::$secretStore[$this->secretRef]);
    }

    private static function storeSecret(string $apiKey): string
    {
        $ref = bin2hex(random_bytes(16));
        self::$secretStore[$ref] = $apiKey;

        return $ref;
    }

    /**
     * Validate and normalize client options, applying defaults.
     *
     * @param Options $options
     */
    public static function resolve(array $options): self
    {
        $apiKey = $options['apiKey'] ?? getenv(self::API_KEY_ENV);
        if (!is_string($apiKey) || trim($apiKey) === '') {
            throw new \InvalidArgumentException(
                'Cotizave: an API key is required. Pass ["apiKey" => ...] or set the '
                . self::API_KEY_ENV . ' environment variable.'
            );
        }

        $retry = $options['retry'] ?? [];
        $baseDelay = self::number($retry['baseDelay'] ?? self::DEFAULT_RETRY_BASE_DELAY, 'retry.baseDelay', 0.0);
        $maxDelay = self::number($retry['maxDelay'] ?? self::DEFAULT_RETRY_MAX_DELAY, 'retry.maxDelay', 0.0);
        $statuses = $retry['statuses'] ?? self::DEFAULT_RETRY_STATUSES;
        foreach ($statuses as $s) {
            if (!is_int($s)) {
                throw new \InvalidArgumentException('Cotizave: retry.statuses must be a list of HTTP status codes.');
            }
        }

        $userAgent = self::baseUserAgent();
        if (isset($options['userAgent'])) {
            $userAgent = trim($userAgent . ' ' . self::validateHeaderValue($options['userAgent'], 'userAgent'));
        }

        return new self(
            secretRef: self::storeSecret($apiKey),
            baseUrl: self::resolveBaseUrl($options),
            timeout: self::number($options['timeout'] ?? self::DEFAULT_TIMEOUT, 'timeout', 0.001),
            historyTimeout: self::number($options['historyTimeout'] ?? self::DEFAULT_HISTORY_TIMEOUT, 'historyTimeout', 0.001),
            maxRetries: self::integer($options['maxRetries'] ?? self::DEFAULT_MAX_RETRIES, 'maxRetries', 0),
            maxRetryDelay: self::number($options['maxRetryDelay'] ?? self::DEFAULT_MAX_RETRY_DELAY, 'maxRetryDelay', 0.0),
            maxResponseBytes: self::integer($options['maxResponseBytes'] ?? self::DEFAULT_MAX_RESPONSE_BYTES, 'maxResponseBytes', 1),
            retryBaseDelay: $baseDelay,
            retryMaxDelay: max($maxDelay, $baseDelay),
            retryStatuses: array_values($statuses),
            retryRateLimited: $retry['retryRateLimited'] ?? true,
            shouldRetry: isset($retry['shouldRetry']) ? \Closure::fromCallable($retry['shouldRetry']) : null,
            onRetry: isset($retry['onRetry']) ? \Closure::fromCallable($retry['onRetry']) : null,
            headers: self::validateHeaders($options['headers'] ?? [], 'headers'),
            language: isset($options['language']) ? self::validateHeaderValue($options['language'], 'language') : null,
            userAgent: $userAgent,
            onResponse: isset($options['onResponse']) ? \Closure::fromCallable($options['onResponse']) : null,
            httpClient: $options['httpClient'] ?? new Client(),
        );
    }

    /**
     * The public configuration, with the API key and hooks omitted, so logging
     * or serializing it is always safe.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'baseUrl' => $this->baseUrl,
            'timeout' => $this->timeout,
            'historyTimeout' => $this->historyTimeout,
            'maxRetries' => $this->maxRetries,
            'maxRetryDelay' => $this->maxRetryDelay,
            'maxResponseBytes' => $this->maxResponseBytes,
            'userAgent' => $this->userAgent,
            'language' => $this->language,
        ];
    }

    private static function baseUserAgent(): string
    {
        return 'cotizave-php/' . self::SDK_VERSION . ' php/' . PHP_VERSION;
    }

    /**
     * @param Options $options
     */
    private static function resolveBaseUrl(array $options): string
    {
        $baseUrl = $options['baseUrl'] ?? self::DEFAULT_BASE_URL;
        $parts = parse_url($baseUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException("Cotizave: baseUrl must be a valid http(s) URL, got: {$baseUrl}");
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme === 'http' && ($options['allowInsecureHttp'] ?? false) !== true) {
            throw new \InvalidArgumentException(
                'Cotizave: baseUrl uses http:// which would send your API key in cleartext. '
                . 'Use https://, or set ["allowInsecureHttp" => true] for local testing.'
            );
        }
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new \InvalidArgumentException("Cotizave: baseUrl must be an http(s) URL, got: {$baseUrl}");
        }

        return rtrim($baseUrl, '/');
    }

    /**
     * Validate caller-supplied headers and return a clean copy.
     *
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    public static function validateHeaders(array $headers, string $optionName): array
    {
        $clean = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name) || preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $name) !== 1) {
                throw new \InvalidArgumentException("Cotizave: invalid header name in {$optionName}: " . var_export($name, true));
            }
            if (strtolower($name) === self::RESERVED_HEADER) {
                throw new \InvalidArgumentException("Cotizave: {$optionName} cannot set the X-API-Key header; use the apiKey option.");
            }
            $clean[$name] = self::validateHeaderValue($value, "{$optionName}.{$name}");
        }

        return $clean;
    }

    /** Reject non-strings and CR/LF/control characters (header injection). */
    public static function validateHeaderValue(mixed $value, string $name): string
    {
        if (!is_string($value) || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new \InvalidArgumentException("Cotizave: {$name} must be a string without control characters.");
        }

        return $value;
    }

    private static function number(float|int $value, string $name, float $min): float
    {
        $v = (float) $value;
        if (!is_finite($v) || $v < $min) {
            throw new \InvalidArgumentException("Cotizave: {$name} must be a finite number >= {$min}.");
        }

        return $v;
    }

    private static function integer(int $value, string $name, int $min): int
    {
        if ($value < $min) {
            throw new \InvalidArgumentException("Cotizave: {$name} must be an integer >= {$min}.");
        }

        return $value;
    }
}
