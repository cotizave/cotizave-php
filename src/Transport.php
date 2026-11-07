<?php

declare(strict_types=1);

namespace Cotizave;

use Cotizave\Exception\ConnectionException;
use Cotizave\Exception\CotizaveException;
use Cotizave\Exception\TimeoutException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;

/**
 * The typed HTTP layer, built on Guzzle.
 *
 * Adds what is specific to Cotizave on top of the HTTP client: authentication,
 * pinning every request to the configured origin, refusing redirects, the retry
 * policy (quota vs. burst limits), a response-size cap, and mapping failures to
 * typed CotizaveException. Resource classes sit on top of it.
 *
 * @internal
 *
 * @phpstan-type RequestConfig array{timeout?: float|int, maxRetries?: int, headers?: array<string, string>, language?: string}
 */
final class Transport
{
    private readonly string $origin;

    public function __construct(private readonly Config $config)
    {
        $this->origin = self::originOf($config->baseUrl);
    }

    public function historyTimeout(): float
    {
        return $this->config->historyTimeout;
    }

    /**
     * JSON convenience GET: returns the parsed body.
     *
     * @param array<string, scalar|null> $query
     * @param RequestConfig               $config
     */
    public function get(string $path, array $query = [], array $config = []): mixed
    {
        return $this->request('GET', $path, $query, null, $config)->data;
    }

    /**
     * JSON convenience POST.
     *
     * @param array<string, mixed> $body
     * @param RequestConfig        $config
     */
    public function post(string $path, array $body, array $config = []): mixed
    {
        return $this->request('POST', $path, [], $body, $config)->data;
    }

    /**
     * Full request with metadata. Failed attempts are retried per the policy,
     * bounded by maxRetries and the maxRetryDelay budget.
     *
     * @param array<string, scalar|null> $query
     * @param array<string, mixed>|null  $body
     * @param RequestConfig              $config
     * @param array{maxResponseBytes?: int, ifNoneMatch?: string, accept?: string, parse?: 'json'|'text'} $extra
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null, array $config = [], array $extra = []): RawResult
    {
        $url = $this->buildUrl($path, $query);
        $headers = $this->buildHeaders($method, $config, $extra, $body !== null);
        $timeout = isset($config['timeout']) ? (float) $config['timeout'] : $this->config->timeout;
        $maxBytes = $extra['maxResponseBytes'] ?? $this->config->maxResponseBytes;
        $limit = $config['maxRetries'] ?? $this->config->maxRetries;
        $parse = $extra['parse'] ?? 'json';

        $budget = $this->config->maxRetryDelay;
        $spent = 0.0;
        $attempt = 0;

        while (true) {
            try {
                return $this->exchange($method, $path, $url, $headers, $body, $timeout, $maxBytes, $parse);
            } catch (CotizaveException $error) {
                if ($attempt >= $limit || !Retry::shouldRetry($error, $attempt, $method, $path, $this->config)) {
                    throw $error;
                }
                $delay = Retry::nextDelaySeconds($error, $attempt, $budget - $spent, $this->config);
                if ($delay === null) {
                    throw $error;
                }
                $spent += $delay;
                if ($this->config->onRetry !== null) {
                    self::safeCall($this->config->onRetry, new RetryEvent($error, $attempt + 1, $delay, $method, $path));
                }
                if ($delay > 0) {
                    usleep((int) round($delay * 1_000_000));
                }
                $attempt++;
            }
        }
    }

    /**
     * A single HTTP exchange, with errors already mapped to CotizaveException.
     *
     * @param array<string, string>    $headers
     * @param array<string, mixed>|null $body
     * @param 'json'|'text'            $parse
     */
    private function exchange(string $method, string $path, string $url, array $headers, ?array $body, float $timeout, int $maxBytes, string $parse): RawResult
    {
        $content = $body !== null ? (string) json_encode($body) : null;
        $request = new Request($method, $url, $headers, $content);

        try {
            $response = $this->config->httpClient->send($request, [
                RequestOptions::ALLOW_REDIRECTS => false,
                RequestOptions::HTTP_ERRORS => false,
                RequestOptions::STREAM => true,
                RequestOptions::TIMEOUT => $timeout,
                RequestOptions::CONNECT_TIMEOUT => $timeout,
            ]);
        } catch (ConnectException $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'timed out') !== false || stripos($msg, 'timeout') !== false) {
                throw new TimeoutException("Request to {$url} timed out after {$timeout}s.", errorCode: 'timeout', previous: $e);
            }
            throw new ConnectionException("Network error while requesting {$url}: {$msg}", errorCode: 'connection_error', previous: $e);
        } catch (TransferException $e) {
            throw new ConnectionException("Network error while requesting {$url}: {$e->getMessage()}", errorCode: 'connection_error', previous: $e);
        }

        $status = $response->getStatusCode();

        if ($status >= 300 && $status < 400 && $response->hasHeader('Location')) {
            // The API is not expected to redirect; following one could forward the
            // X-API-Key header to another origin. Refuse it (and never follow it:
            // ALLOW_REDIRECTS is false even for an injected client).
            $meta = Headers::buildMeta($status, $response->getHeaders());
            throw new CotizaveException(
                'Refusing to follow a redirect to ' . var_export($response->getHeaderLine('Location'), true)
                    . '; the API is not expected to redirect and following one could leak the API key.',
                status: $status,
                errorCode: 'unexpected_redirect',
                meta: $meta,
            );
        }

        $text = $this->readCapped($response, $maxBytes, $url);
        $meta = Headers::buildMeta($status, $response->getHeaders());

        if ($this->config->onResponse !== null) {
            self::safeCall($this->config->onResponse, new ResponseEvent($method, $path, $meta));
        }

        if ($status === 304) {
            return new RawResult(null, $meta, true);
        }
        if ($status >= 400) {
            [$bodyObj, $rawText] = self::parseErrorBody($text);
            throw CotizaveException::fromResponse($status, $bodyObj, $meta, $rawText);
        }
        if ($parse === 'text') {
            return new RawResult($text, $meta, false);
        }
        if (trim($text) === '') {
            return new RawResult(null, $meta, false);
        }
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new CotizaveException(
                "Expected JSON from {$url} but could not parse the response body.",
                status: $status,
                errorCode: 'invalid_json_response',
                details: ['non_json_body' => mb_substr($text, 0, 500)],
                meta: $meta,
                previous: $e,
            );
        }

        return new RawResult($decoded, $meta, false);
    }

    private function readCapped(ResponseInterface $response, int $maxBytes, string $url): string
    {
        $stream = $response->getBody();
        $buffer = '';
        $total = 0;
        while (!$stream->eof()) {
            $chunk = $stream->read(16384);
            if ($chunk === '') {
                break;
            }
            $total += strlen($chunk);
            if ($total > $maxBytes) {
                $stream->close();
                throw new CotizaveException(
                    "Response body exceeded the maximum allowed size ({$maxBytes} bytes).",
                    errorCode: 'response_too_large',
                );
            }
            $buffer .= $chunk;
        }

        return $buffer;
    }

    /**
     * @param array<string, scalar|null> $query
     */
    private function buildUrl(string $path, array $query): string
    {
        $full = $this->config->baseUrl . $path;
        if (self::originOf($full) !== $this->origin) {
            throw new CotizaveException(
                "Refusing to send a request to a different origin. A request path must stay on {$this->origin}.",
                errorCode: 'invalid_request_path',
            );
        }

        $pairs = [];
        foreach ($query as $key => $value) {
            if ($value === null) {
                continue;
            }
            $str = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            $pairs[] = rawurlencode($key) . '=' . rawurlencode($str);
        }
        if ($pairs === []) {
            return $full;
        }

        return $full . (str_contains($full, '?') ? '&' : '?') . implode('&', $pairs);
    }

    /**
     * Header precedence (low to high): SDK defaults, client-wide headers and
     * language, per-call headers and language, explicit accept / ifNoneMatch,
     * and finally the API key, which nothing can override.
     *
     * @param RequestConfig $config
     * @param array{maxResponseBytes?: int, ifNoneMatch?: string, accept?: string, parse?: 'json'|'text'} $extra
     * @return array<string, string>
     */
    private function buildHeaders(string $method, array $config, array $extra, bool $hasBody): array
    {
        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => $this->config->userAgent,
        ];
        foreach ($this->config->headers as $name => $value) {
            $headers[$name] = $value;
        }
        if ($this->config->language !== null) {
            $headers['Accept-Language'] = $this->config->language;
        }

        $perCall = self::guard(fn (): array => Config::validateHeaders($config['headers'] ?? [], 'headers'));
        foreach ($perCall as $name => $value) {
            $headers[$name] = $value;
        }
        if (isset($config['language'])) {
            $headers['Accept-Language'] = self::guard(fn (): string => Config::validateHeaderValue($config['language'], 'language'));
        }
        if (isset($extra['accept'])) {
            $headers['Accept'] = self::guard(fn (): string => Config::validateHeaderValue($extra['accept'], 'accept'));
        }
        if (isset($extra['ifNoneMatch'])) {
            $headers['If-None-Match'] = self::guard(fn (): string => Config::validateHeaderValue($extra['ifNoneMatch'], 'ifNoneMatch'));
        }
        if ($method === 'POST' && $hasBody) {
            $headers['Content-Type'] = 'application/json';
        }
        $headers['X-API-Key'] = $this->config->apiKey();

        return $headers;
    }

    /**
     * The scheme/host/port origin of a URL, parsed with the SAME parser Guzzle
     * uses to build the request (GuzzleHttp\Psr7\Uri). Using one parser for both
     * the origin check and the actual request removes any parser-differential
     * that could let a crafted path slip the API key to another host. A malformed
     * URL returns a sentinel that never matches a real origin, so the caller gets
     * a typed invalid_request_path error instead of an uncaught MalformedUriException.
     */
    private static function originOf(string $url): string
    {
        try {
            $uri = new Uri($url);
        } catch (\Throwable) {
            return "\0invalid";
        }
        $host = strtolower($uri->getHost());
        if ($host === '') {
            return "\0invalid";
        }
        $port = $uri->getPort();

        return strtolower($uri->getScheme()) . '://' . $host . ($port !== null ? ':' . $port : '');
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: string|null}
     */
    private static function parseErrorBody(string $text): array
    {
        $stripped = trim($text);
        if ($stripped === '') {
            return [null, null];
        }
        try {
            /** @var mixed $parsed */
            $parsed = json_decode($stripped, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [null, $text];
        }
        if (is_array($parsed)) {
            /** @var array<string, mixed> $parsed */
            return [$parsed, null];
        }

        return [null, $text];
    }

    /**
     * Turn a header-validation failure of per-call input into a catchable error.
     *
     * @template T
     * @param callable(): T $validate
     * @return T
     */
    private static function guard(callable $validate): mixed
    {
        try {
            return $validate();
        } catch (\InvalidArgumentException $e) {
            throw new CotizaveException($e->getMessage(), errorCode: 'invalid_header_value', previous: $e);
        }
    }

    private static function safeCall(\Closure $hook, object $event): void
    {
        try {
            $hook($event);
        } catch (\Throwable) {
            // Observability hooks are best-effort: a throwing hook never breaks a request.
        }
    }
}
