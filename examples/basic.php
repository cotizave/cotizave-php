<?php

declare(strict_types=1);

/**
 * Basic usage of the Cotizave PHP SDK.
 *
 * Run it with your API key in the environment:
 *
 *   export COTIZAVE_API_KEY="ctz_live_..."
 *   php examples/basic.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Cotizave\Cotizave;
use Cotizave\Exception\CotizaveException;
use Cotizave\Exception\RateLimitException;

// Reads COTIZAVE_API_KEY from the environment; or pass ['apiKey' => '...'].
$client = new Cotizave();

// Colombia's TRM, by its canonical id.
$trm = $client->rates->get('CO', 'co-trm');
echo 'TRM: ', $trm['mid'] ?? 'n/a', PHP_EOL;

// Every rate published for a country.
$board = $client->rates->list('AR');
foreach ($board['rates'] as $rate) {
    echo $rate['id'], ' -> ', $rate['mid'] ?? 'n/a', PHP_EOL;
}

// A conversion, resolved on Cotizave (never computed client-side).
try {
    $result = $client->convert(['from' => 'USD', 'to' => 'BRL', 'amount' => 100]);
    echo '100 USD = ', $result['to']['amount'], ' ', $result['to']['currency'], PHP_EOL;
} catch (RateLimitException $e) {
    echo 'Rate limited: ', $e->isQuotaExhausted() ? 'quota' : 'slow down', PHP_EOL;
} catch (CotizaveException $e) {
    echo 'API error: ', $e->status, ' ', $e->errorCode, ' ', $e->getMessage(), PHP_EOL;
}

// A short daily history series.
$series = $client->history->daily('AR', 'ar-official', ['from' => '2026-09-01', 'to' => '2026-09-07']);
echo 'history points: ', count($series['points'] ?? []), PHP_EOL;
