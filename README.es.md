<div align="center">

[English](./README.md) · **Español**

# SDK de PHP de Cotizave

Cliente oficial de PHP, totalmente tipado, para la **API v2 de Cotizave** — tasas
de cambio multipaís, conversión de monedas, series históricas, tasas de bancos y
calendarios, con una sola API key.

[![Packagist](https://img.shields.io/packagist/v/cotizave/sdk?color=F28D1A&logo=packagist&logoColor=white)](https://packagist.org/packages/cotizave/sdk)
[![PHP](https://img.shields.io/packagist/dependency-v/cotizave/sdk/php?label=php)](https://packagist.org/packages/cotizave/sdk)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](./LICENSE)

[Hub de API y docs](https://github.com/cotizave/exchange-rates-api) · [Documentación](https://docs.cotizave.com) · [Referencia de la API](https://docs.cotizave.com/api/v2/introduction) · [Obtené una API key](https://app.cotizave.com)

</div>

---

## Instalación

```bash
composer require cotizave/sdk
```

Requiere PHP 8.3+. El motor HTTP es [Guzzle](https://docs.guzzlephp.org/); se puede
inyectar cualquier cliente PSR-18 en su lugar.

## Inicio rápido

```php
use Cotizave\Cotizave;

// Lee COTIZAVE_API_KEY del entorno; o pasá ['apiKey' => '...'].
$client = new Cotizave();

// Todas las tasas publicadas de un país.
$response = $client->rates->list('AR');
foreach ($response['rates'] as $rate) {
    echo $rate['id'], ' ', $rate['mid'] ?? '', PHP_EOL;
}

// Una tasa por su id canónico.
$trm = $client->rates->get('CO', 'co-trm');
echo $trm['mid'];

// Una conversión (resuelta en Cotizave, nunca en el cliente).
$result = $client->convert(['from' => 'USD', 'to' => 'BRL', 'amount' => 100]);
echo $result['to']['amount'];
```

> **Mantené tu API key del lado del servidor.** Se envía solo en la cabecera
> `X-API-Key`. Nunca la incluyas en código del cliente.

## Autenticación

```php
$client = new Cotizave(['apiKey' => 'ctz_live_...']); // explícita
$client = new Cotizave();                              // desde COTIZAVE_API_KEY
```

## Qué podés hacer

| Área | Llamada |
| --- | --- |
| Tasas de un país | `$client->rates->list('CO')` |
| Una tasa por id | `$client->rates->get('CO', 'co-trm')` |
| Tablero pasado de un país | `$client->rates->snapshot('AR', '2026-10-01')` |
| Oficial de cada país | `$client->rates->global(['symbols' => ['BRL', 'EUR']])` |
| Convertir un monto | `$client->convert(['from' => 'USD', 'to' => 'BRL', 'amount' => 100])` |
| Convertir hasta 50 montos | `$client->convertBulk([...])` |
| Histórico puntual | `$client->history->get('CO', 'co-trm', '2026-10-01')` |
| Serie histórica diaria | `$client->history->daily('AR', 'ar-official', ['from' => '...', 'to' => '...'])` |
| Estadísticas del período | `$client->history->stats('AR', 'ar-official', ['from' => '...', 'to' => '...', 'movingAverage' => 7])` |
| Capturas crudas (paginadas) | `$client->history->raw(...)` / `$client->history->iterate(...)` |
| Cobertura del histórico | `$client->history->limits()` |
| Catálogo de tasas | `$client->markets('VE')` |
| Brecha vs otra tasa | `$client->spread('VE', ['against' => 've-paralelo'])` |
| Mejor ruta ejecutable | `$client->route('VE', ['from' => 'USD', 'to' => 'VES', 'amount' => 100])` |
| Tasas por banco | `$client->bankRates('VE')` |
| Países / monedas | `$client->countries->list()` · `$client->currencies->list()` |
| Bancos / emisores | `$client->banks->list('VE')` · `$client->issuers->list()` |
| Feriados y días hábiles | `$client->holidays->list('AR')` · `$client->holidays->businessDays('VE', ['from' => '2026-10-01', 'days' => 5])` |
| Estables (USD) | `$client->crypto->list()` · `$client->crypto->get('usdt-usd')` |

Las respuestas son `array` asociativos, anotados con array shapes de PHPStan para
análisis estático. Los campos presentes dependen de tu plan, el país y el mercado
— leelos de forma defensiva y consultá la
[referencia de la API](https://docs.cotizave.com/api/v2/introduction).

### Iterar el histórico crudo de forma segura

```php
foreach ($client->history->iterate('VE', 've-bcv', ['maxPages' => 10]) as $point) {
    echo $point['date'], ' ', $point['mid'] ?? '', PHP_EOL;
}
```

`iterate` sigue los cursores del servidor por vos y se detiene en `maxPages`
(por defecto 50), así nunca descarga en silencio todo un histórico.

## Errores

Cada fallo lanza una subclase de `Cotizave\Exception\CotizaveException`:

```php
use Cotizave\Cotizave;
use Cotizave\Exception\CotizaveException;
use Cotizave\Exception\NotFoundException;
use Cotizave\Exception\RateLimitException;

$client = new Cotizave();
try {
    $rate = $client->rates->get('CO', 'co-trm');
} catch (RateLimitException $e) {
    echo $e->isQuotaExhausted() ? 'Cuota mensual agotada' : "Bajá el ritmo; reintentá en {$e->retryAfter()}s";
} catch (NotFoundException $e) {
    echo 'No existe esa tasa';
} catch (CotizaveException $e) {
    echo $e->status, ' ', $e->errorCode, ' ', $e->getMessage();
}
```

| Excepción | HTTP |
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
| `ConnectionException` / `TimeoutException` | sin respuesta (red/timeout) |

Cada excepción lleva `status`, `errorCode`, `getMessage()`, `field`/`details`
opcionales, y `rateLimit()` / `headers()` / `requestId()`.

## Configuración

Todo tiene un valor por defecto seguro para producción; sobreescribí solo lo que necesites:

```php
use Cotizave\Cotizave;

$client = new Cotizave([
    'apiKey' => 'ctz_live_...',
    'timeout' => 5.0,              // timeout por petición, en segundos
    'historyTimeout' => 10.0,      // timeout más largo para lecturas de histórico
    'maxRetries' => 2,             // reintentos extra para peticiones elegibles
    'maxRetryDelay' => 10.0,       // presupuesto total de backoff por llamada, en segundos
    'maxResponseBytes' => 25_000_000,
    'language' => 'es',            // Accept-Language para nombres localizados
    'headers' => ['X-Trace-Id' => '...'],
    'retry' => [
        'baseDelay' => 0.25,
        'maxDelay' => 2.0,
        'retryRateLimited' => true,
        'onRetry' => fn ($e) => error_log("reintentando {$e->path} en {$e->delay}s"),
    ],
    'onResponse' => fn ($e) => error_log("{$e->meta->status} remaining={$e->meta->rateLimit->remaining}"),
]);
```

Cada método acepta overrides por llamada como último argumento (`timeout`,
`maxRetries`, `headers`, `language`):

```php
$client->rates->list('AR', [], ['timeout' => 2.0, 'maxRetries' => 0]);
```

### Política de reintentos

Solo se reintentan peticiones idempotentes (GET). Un `429` se reintenta solo
cuando es un límite de velocidad (por minuto), respetando el `Retry-After` del
servidor; un `429` por cuota mensual agotada nunca se reintenta. Los errores de
conexión y timeout, y los 5xx configurados, se reintentan con backoff exponencial
y jitter completo, acotados por `maxRetries` y el presupuesto `maxRetryDelay`.

## Exportar CSV / XML

```php
$csv = $client->export('/v2/fx/ar/rates', 'csv');
```

## Peticiones de bajo nivel

```php
$result = $client->request('GET', '/v2/fx/co/rates/co-trm', ['ifNoneMatch' => '"abc"']);
echo $result->notModified, ' ', $result->meta->status;
var_dump($result->data);
```

## Inyectar tu propio cliente HTTP

```php
use Cotizave\Cotizave;
use GuzzleHttp\Client;

$client = new Cotizave(['httpClient' => new Client(['proxy' => '...'])]);
```

El SDK siempre envía con los redirects deshabilitados, así que un cliente
inyectado nunca puede ser inducido a reenviar tu API key a un destino de redirect.

## Seguridad

- La API key se envía solo en la cabecera `X-API-Key` y nunca se expone en
  `$client->config`, en los mensajes de excepción ni en un query string.
- Cada petición queda fijada al origen configurado; un path que resuelve a otro
  host se rechaza, y los redirects nunca se siguen.
- El cuerpo de la respuesta se limita a `maxResponseBytes` para protegerte de una
  respuesta sobredimensionada o una bomba de descompresión.

¿Encontraste un problema de seguridad? Mirá [SECURITY.md](./SECURITY.md).

## Licencia

[MIT](./LICENSE)
