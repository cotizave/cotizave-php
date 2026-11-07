<?php

declare(strict_types=1);

namespace Cotizave;

use Cotizave\Exception\ConnectionException;
use Cotizave\Exception\CotizaveException;
use Cotizave\Exception\TimeoutException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The typed HTTP layer, built on Guzzle.
 *
 * The HTTP mechanics are the library's: retries run on Guzzle's HandlerStack via
 * Middleware::retry, timeouts/redirects are Guzzle request options. This class
 * supplies only the Cotizave-specific policy and SDK logic: the retry decision
 * (quota vs burst 429, Retry-After, jitter, budget), authentication, origin
 * pinning, error -> exception mapping, rate-limit header parsing, and the
 * max-response-bytes cap (which Guzzle has no feature for).
 *
 * @internal
 *
 * @phpstan-type RequestConfig array{timeout?: float|int, maxRetries?: int, headers?: array<string, string>, language?: string}
 */
final class Transport
{
    private readonly string $origin;
    private readonly ClientInterface $client;

    // Per-request retry state, read by the Guzzle retry middleware's decider/delay
    // callbacks. Safe as instance fields: the SDK issues one blocking request at a
    // time (no concurrency), and the middleware runs synchronously inside send().
    private int $retryLimit = 0;
    private string $retryMethod = 'GET';
    private string $retryPath = '';
    private float $retryBudgetLeft = 0.0;
    private int $nextDelayMs = 0;
    private ?CotizaveException $lastRetryError = null;

    public function __construct(private readonly Config $config)
    {
        $this->origin = self::originOf($config->baseUrl);

        // The retry loop is Guzzle's (Middleware::retry on the HandlerStack); we
        // supply only the decide/delay policy. HandlerStack::create() gives the
        // default cURL handler (or the caller-supplied handler) plus Guzzle's own
        // middlewares (redirect, http_errors, ...).
        $stack = HandlerStack::create($config->handler);
        $stack->push(Middleware::retry(
            fn (int $retries, RequestInterface $request, ?ResponseInterface $response = null, ?\Throwable $reason = null): bool
                => $this->decideRetry($retries, $request, $response, $reason),
            fn (int $retries, ?ResponseInterface $response = null, ?RequestInterface $request = null): int
                => $this->retryDelayMs($retries),
        ));
        $this->client = new Client(['handler' => $stack]);
    }

    public function historyTimeout(): float
    {
        return $this->config->historyTimeout;
    }

    /**
     * @param array<string, scalar|null> $query
     * @param RequestConfig              $config
     */
    public function get(string $path, array $query = [], array $config = []): mixed
    {
        return $this->request('GET', $path, $query, null, $config)->data;
    }

    /**
     * @param array<string, mixed> $body
     * @param RequestConfig        $config
     */
    public function post(string $path, array $body, array $config = []): mixed
    {
        return $this->request('POST', $path, [], $body, $config)->data;
    }

    /**
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
        $parse = $extra['parse'] ?? 'json';

        // Hand the retry middleware the policy inputs for this call.
        $this->retryLimit = $config['maxRetries'] ?? $this->config->maxRetries;
        $this->retryMethod = $method;
        $this->retryPath = $path;
        $this->retryBudgetLeft = $this->config->maxRetryDelay;
        $this->lastRetryError = null;

        return $this->exchange($method, $path, $url, $headers, $body, $timeout, $maxBytes, $parse);
    }

    /**
     * One logical exchange. Guzzle performs any retries internally (via the retry
     * middleware); this maps the final response/exception to the SDK's types.
     *
     * @param array<string, string>     $headers
     * @param array<string, mixed>|null $body
     * @param 'json'|'text'            $parse
     */
    private function exchange(string $method, string $path, string $url, array $headers, ?array $body, float $timeout, int $maxBytes, string $parse): RawResult
    {
        $content = $body !== null ? (string) json_encode($body) : null;
        $request = new Request($method, $url, $headers, $content);

        try {
            $response = $this->client->send($request, [
                RequestOptions::ALLOW_REDIRECTS => false,
                RequestOptions::HTTP_ERRORS => false,
                // Deliberately NOT streaming: a streamed (lazy) body read is not
                // bounded by the request timeout on all platforms (validated — it
                // hung on Linux). A non-streamed request downloads the body inside
                // cURL's TIMEOUT, so the whole exchange is reliably time-bounded.
                // decode_content=false means we never inflate a compressed body,
                // which removes the decompression-bomb reason for streaming.
                RequestOptions::DECODE_CONTENT => false,
                RequestOptions::TIMEOUT => $timeout,
                RequestOptions::CONNECT_TIMEOUT => $timeout,
            ]);
        } catch (ConnectException $e) {
            throw $this->fromTransferError($e, $url);
        } catch (TransferException $e) {
            throw new ConnectionException("Network error while requesting {$url}: {$e->getMessage()}", errorCode: 'connection_error', previous: $e);
        }

        $status = $response->getStatusCode();
        $meta = Headers::buildMeta($status, $response->getHeaders());

        if ($status >= 300 && $status < 400 && $response->hasHeader('Location')) {
            // The API is not expected to redirect; following one could forward the
            // X-API-Key header to another origin. allow_redirects=false already
            // prevents following it; turn the response itself into an error.
            throw new CotizaveException(
                'Refusing to follow a redirect to ' . var_export($response->getHeaderLine('Location'), true)
                    . '; the API is not expected to redirect and following one could leak the API key.',
                status: $status,
                errorCode: 'unexpected_redirect',
                meta: $meta,
            );
        }

        // Max-response-bytes cap (Guzzle has no option for this). Reject early on a
        // declared Content-Length, then read defensively with the same bound in
        // case the length is absent or understated.
        $declared = $response->getHeaderLine('Content-Length');
        if ($declared !== '' && ctype_digit($declared) && (int) $declared > $maxBytes) {
            throw new CotizaveException(
                "Response body exceeded the maximum allowed size ({$maxBytes} bytes).",
                status: $status,
                errorCode: 'response_too_large',
                meta: $meta,
            );
        }

        try {
            $text = $this->readCapped($response, $maxBytes);
        } catch (CotizaveException $e) {
            throw $e; // response_too_large
        } catch (\Throwable $e) {
            // A failure while materialising the body surfaces as a generic stream
            // exception. Map it into the SDK hierarchy so it is typed (and, for a
            // GET, retryable) rather than leaking a raw \RuntimeException. The
            // request time itself is already bounded by the send-phase timeout.
            throw new ConnectionException("Network error while reading the response body from {$url}: {$e->getMessage()}", errorCode: 'connection_error', meta: $meta, previous: $e);
        }

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

    /**
     * Materialise the body with the byte cap, using the library helper (no
     * hand-rolled loop). The body has already been downloaded within the request
     * timeout (non-streaming); copyToString caps what we read into a string at
     * maxBytes+1, and more than maxBytes means the body is over the cap.
     */
    private function readCapped(ResponseInterface $response, int $maxBytes): string
    {
        $text = Utils::copyToString($response->getBody(), $maxBytes + 1);
        if (strlen($text) > $maxBytes) {
            throw new CotizaveException(
                "Response body exceeded the maximum allowed size ({$maxBytes} bytes).",
                errorCode: 'response_too_large',
            );
        }

        return $text;
    }

    // -- retry policy plugged into Guzzle's retry middleware -----------------

    private function decideRetry(int $retries, RequestInterface $request, ?ResponseInterface $response, ?\Throwable $reason): bool
    {
        if ($retries >= $this->retryLimit) {
            return false;
        }
        $error = $this->mapForRetry($response, $reason);
        if ($error === null) {
            return false; // a success response, or an error we do not understand
        }
        if (!Retry::shouldRetry($error, $retries, $request->getMethod(), $this->retryPath, $this->config)) {
            return false;
        }
        $delay = Retry::nextDelaySeconds($error, $retries, $this->retryBudgetLeft, $this->config);
        if ($delay === null) {
            return false; // out of the retry-delay budget
        }
        $this->retryBudgetLeft -= $delay;
        $this->nextDelayMs = (int) round($delay * 1000);
        $this->lastRetryError = $error;

        return true;
    }

    private function retryDelayMs(int $retries): int
    {
        if ($this->config->onRetry !== null && $this->lastRetryError !== null) {
            self::safeCall(
                $this->config->onRetry,
                new RetryEvent($this->lastRetryError, $retries, $this->nextDelayMs / 1000, $this->retryMethod, $this->retryPath),
            );
        }

        return $this->nextDelayMs;
    }

    /**
     * Build the exception used for the retry DECISION from status + headers only
     * (never the body, which must stay unread for the final mapping).
     */
    private function mapForRetry(?ResponseInterface $response, ?\Throwable $reason): ?CotizaveException
    {
        if ($reason !== null) {
            if ($reason instanceof ConnectException) {
                return $this->fromTransferError($reason, (string) $reason->getRequest()->getUri());
            }
            if ($reason instanceof TransferException) {
                return new ConnectionException($reason->getMessage(), errorCode: 'connection_error', previous: $reason);
            }

            return null;
        }
        if ($response === null) {
            return null;
        }
        $status = $response->getStatusCode();
        if ($status < 400) {
            return null;
        }

        return CotizaveException::fromResponse($status, null, Headers::buildMeta($status, $response->getHeaders()));
    }

    private function fromTransferError(ConnectException $e, string $url): CotizaveException
    {
        $msg = $e->getMessage();
        if (stripos($msg, 'timed out') !== false || stripos($msg, 'timeout') !== false) {
            return new TimeoutException("Request to {$url} timed out.", errorCode: 'timeout', previous: $e);
        }

        return new ConnectionException("Network error while requesting {$url}: {$msg}", errorCode: 'connection_error', previous: $e);
    }

    // -- request building ----------------------------------------------------

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
     * uses to build the request (GuzzleHttp\Psr7\Uri). One parser for both the
     * origin check and the request removes any parser-differential, and a
     * malformed URL returns a sentinel that never matches a real origin.
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
