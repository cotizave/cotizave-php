<div align="center">

**English** · [Español](./README.es.md)

# Cotizave PHP SDK

Official, fully-typed PHP client for the **Cotizave API v2** — multi-country
exchange-rate references, currency conversion, historical series, bank rates and
calendars, behind a single API key.

[![Packagist](https://img.shields.io/packagist/v/cotizave/sdk?color=F28D1A&logo=packagist&logoColor=white)](https://packagist.org/packages/cotizave/sdk)
[![PHP](https://img.shields.io/packagist/dependency-v/cotizave/sdk/php?label=php)](https://packagist.org/packages/cotizave/sdk)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](./LICENSE)

[API & docs hub](https://github.com/cotizave/exchange-rates-api) · [Documentation](https://docs.cotizave.com) · [API reference](https://docs.cotizave.com/api/v2/introduction) · [Get an API key](https://app.cotizave.com)

</div>

---

## Install

```bash
composer require cotizave/sdk
```

Requires PHP 8.3+. The HTTP engine is [Guzzle](https://docs.guzzlephp.org/); any
PSR-18 client can be injected instead.

## Quick start

```php
use Cotizave\Cotizave;

// Reads COTIZAVE_API_KEY from the environment; or pass ['apiKey' => '...'].
$client = new Cotizave();

// Every rate published for a country.
$response = $client->rates->list('AR');
foreach ($response['rates'] as $rate) {
    echo $rate['id'], ' ', $rate['mid'] ?? '', PHP_EOL;
}

// A single rate by its canonical id.
$trm = $client->rates->get('CO', 'co-trm');
echo $trm['mid'];

// A currency conversion (resolved on Cotizave, never computed client-side).
$result = $client->convert(['from' => 'USD', 'to' => 'BRL', 'amount' => 100]);
echo $result['to']['amount'];
```

> **Keep your API key server-side.** It is sent only as the `X-API-Key` header.
> Never ship it in client-side code.

## Authentication

```php
$client = new Cotizave(['apiKey' => 'ctz_live_...']); // explicit
$client = new Cotizave();                              // from COTIZAVE_API_KEY
```

## What you can do

| Area | Call |
| --- | --- |
| Rates of a country | `$client->rates->list('CO')` |
| One rate by id | `$client->rates->get('CO', 'co-trm')` |
| Past board of a country | `$client->rates->snapshot('AR', '2026-10-01')` |
| Official rate of every country | `$client->rates->global(['symbols' => ['BRL', 'EUR']])` |
| Convert one amount | `$client->convert(['from' => 'USD', 'to' => 'BRL', 'amount' => 100])` |
| Convert up to 50 amounts | `$client->convertBulk([...])` |
| Point-in-time history | `$client->history->get('CO', 'co-trm', '2026-10-01')` |
| Daily history series | `$client->history->daily('AR', 'ar-official', ['from' => '...', 'to' => '...'])` |
| Period statistics | `$client->history->stats('AR', 'ar-official', ['from' => '...', 'to' => '...', 'movingAverage' => 7])` |
| Raw captures (paginated) | `$client->history->raw(...)` / `$client->history->iterate(...)` |
| History coverage | `$client->history->limits()` |
| Rate catalog | `$client->markets('VE')` |
| Spread vs another market | `$client->spread('VE', ['against' => 've-paralelo'])` |
| Best executable route | `$client->route('VE', ['from' => 'USD', 'to' => 'VES', 'amount' => 100])` |
| Bank-published rates | `$client->bankRates('VE')` |
| Countries / currencies | `$client->countries->list()` · `$client->currencies->list()` |
| Banks / issuers | `$client->banks->list('VE')` · `$client->issuers->list()` |
| Holidays & business days | `$client->holidays->list('AR')` · `$client->holidays->businessDays('VE', ['from' => '2026-10-01', 'days' => 5])` |
| Stablecoins (USD) | `$client->crypto->list()` · `$client->crypto->get('usdt-usd')` |

Responses are plain associative `array`s, annotated with PHPStan array shapes for
static analysis. The fields present depend on your plan, the country and the
market — read them defensively and check the
[API reference](https://docs.cotizave.com/api/v2/introduction).

### Iterating raw history safely

```php
foreach ($client->history->iterate('VE', 've-bcv', ['maxPages' => 10]) as $point) {
    echo $point['date'], ' ', $point['mid'] ?? '', PHP_EOL;
}
```

`iterate` follows the server's cursors for you and stops at `maxPages`
(default 50) so it never silently downloads an entire history.

## Errors

Every failure throws a subclass of `Cotizave\Exception\CotizaveException`:

```php
use Cotizave\Cotizave;
use Cotizave\Exception\CotizaveException;
use Cotizave\Exception\NotFoundException;
use Cotizave\Exception\RateLimitException;

$client = new Cotizave();
try {
    $rate = $client->rates->get('CO', 'co-trm');
} catch (RateLimitException $e) {
    echo $e->isQuotaExhausted() ? 'Monthly quota exhausted' : "Slow down; retry after {$e->retryAfter()}s";
} catch (NotFoundException $e) {
    echo 'No such rate';
} catch (CotizaveException $e) {
    echo $e->status, ' ', $e->errorCode, ' ', $e->getMessage();
}
```

| Exception | HTTP |
| --- | --- |
| `BadRequestException` | 400 |
| `AuthenticationException` | 401 |
| `FeatureNotInPlanException` | 402 |
| `ForbiddenException` | 403 |
| `NotFoundException` | 404 |
| `NotAcceptableException` | 406 |
| `UnprocessableException` | 422 |
| `RateLimitException` | 429 |
| `ServerException` | 5xx |
| `ConnectionException` / `TimeoutException` | no response (network/timeout) |

Each exception carries `status`, `errorCode`, `getMessage()`, optional
`field`/`details`, and `rateLimit()` / `headers()` / `requestId()`.

## Configuration

Everything has a production-safe default; override only what you need:

```php
use Cotizave\Cotizave;

$client = new Cotizave([
    'apiKey' => 'ctz_live_...',
    'timeout' => 5.0,              // per-request timeout, seconds
    'historyTimeout' => 10.0,      // longer timeout for history reads
    'maxRetries' => 2,             // extra retries for eligible requests
    'maxRetryDelay' => 10.0,       // total backoff budget per call, seconds
    'maxResponseBytes' => 25_000_000,
    'language' => 'es',            // Accept-Language for localized names
    'headers' => ['X-Trace-Id' => '...'],
    'retry' => [
        'baseDelay' => 0.25,
        'maxDelay' => 2.0,
        'retryRateLimited' => true,
        'onRetry' => fn ($e) => error_log("retrying {$e->path} in {$e->delay}s"),
    ],
    'onResponse' => fn ($e) => error_log("{$e->meta->status} remaining={$e->meta->rateLimit->remaining}"),
]);
```

Per-call overrides are accepted as the last argument of every method
(`timeout`, `maxRetries`, `headers`, `language`):

```php
$client->rates->list('AR', [], ['timeout' => 2.0, 'maxRetries' => 0]);
```

### Retry policy

Only idempotent (GET) requests are retried. A `429` is retried only when it is a
burst (per-minute) limit, honoring the server's `Retry-After`; a `429` from an
exhausted monthly quota is never retried. Connection and timeout errors, and the
configured 5xx statuses, are retried with exponential backoff and full jitter,
bounded by `maxRetries` and the `maxRetryDelay` budget.

## Exporting CSV / XML

```php
$csv = $client->export('/v2/fx/ar/rates', 'csv');
```

## Low-level requests

```php
$result = $client->request('GET', '/v2/fx/co/rates/co-trm', ['ifNoneMatch' => '"abc"']);
echo $result->notModified, ' ', $result->meta->status;
var_dump($result->data);
```

## Injecting your own HTTP client

```php
use Cotizave\Cotizave;
use GuzzleHttp\Client;

$client = new Cotizave(['httpClient' => new Client(['proxy' => '...'])]);
```

The SDK always sends with redirects disabled, so an injected client can never be
made to forward your API key to a redirect target.

## Security

- The API key is sent only as the `X-API-Key` header and is never exposed on
  `$client->config`, in exception messages, or in a query string.
- Every request is pinned to the configured origin; a path that resolves to a
  different host is refused, and redirects are never followed.
- Response bodies are capped at `maxResponseBytes` to guard against an oversized
  or decompression-bomb response.

Found a security issue? See [SECURITY.md](./SECURITY.md).

## License

[MIT](./LICENSE)
