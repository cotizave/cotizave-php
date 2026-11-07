<?php

declare(strict_types=1);

namespace Cotizave;

use Cotizave\Resources\BanksResource;
use Cotizave\Resources\ConversionResource;
use Cotizave\Resources\CountriesResource;
use Cotizave\Resources\CryptoResource;
use Cotizave\Resources\CurrenciesResource;
use Cotizave\Resources\FxToolsResource;
use Cotizave\Resources\HistoryResource;
use Cotizave\Resources\HolidaysResource;
use Cotizave\Resources\IssuersResource;
use Cotizave\Resources\RatesResource;

/**
 * The Cotizave API v2 client.
 *
 * ```php
 * use Cotizave\Cotizave;
 *
 * $client = new Cotizave();                 // reads COTIZAVE_API_KEY from the env
 * $board  = $client->rates->list('AR');
 * $trm    = $client->rates->get('CO', 'co-trm');
 * $result = $client->convert(['from' => 'USD', 'to' => 'BRL', 'amount' => 100]);
 * ```
 *
 * @phpstan-import-type Options from Config
 * @phpstan-import-type RequestConfig from Transport
 */
final class Cotizave
{
    /** Per-country rate reads and the global officials feed. */
    public readonly RatesResource $rates;
    /** Point-in-time, daily and raw history, plus coverage limits. */
    public readonly HistoryResource $history;
    /** Published countries catalog (Pro+). */
    public readonly CountriesResource $countries;
    /** Currencies catalog (Pro+). */
    public readonly CurrenciesResource $currencies;
    /** Bank identities, with optional search. */
    public readonly BanksResource $banks;
    /** Official-rate issuers directory. */
    public readonly IssuersResource $issuers;
    /** Holiday calendars and business-day math. */
    public readonly HolidaysResource $holidays;
    /** Stablecoin references priced in USD. */
    public readonly CryptoResource $crypto;

    private readonly Transport $transport;
    private readonly ConversionResource $conversion;
    private readonly FxToolsResource $fx;

    /**
     * Resolved, validated configuration without the API key — safe to log.
     *
     * @var array<string, mixed>
     */
    public readonly array $config;

    /**
     * @param Options $options
     */
    public function __construct(array $options = [])
    {
        $config = Config::resolve($options);
        $this->config = $config->toPublicArray();

        $this->transport = new Transport($config);
        $this->rates = new RatesResource($this->transport);
        $this->history = new HistoryResource($this->transport);
        $this->countries = new CountriesResource($this->transport);
        $this->currencies = new CurrenciesResource($this->transport);
        $this->banks = new BanksResource($this->transport);
        $this->issuers = new IssuersResource($this->transport);
        $this->holidays = new HolidaysResource($this->transport);
        $this->crypto = new CryptoResource($this->transport);
        $this->conversion = new ConversionResource($this->transport);
        $this->fx = new FxToolsResource($this->transport);
    }

    /**
     * Convert a single amount. See ConversionResource::convert().
     *
     * @param array{from: string, to: string, amount: float|int, via?: string, market?: string, date?: string} $options
     * @param RequestConfig $config
     * @return array<string, mixed>
     */
    public function convert(array $options, array $config = []): array
    {
        return $this->conversion->convert($options, $config);
    }

    /**
     * Convert up to 50 items in one call. See ConversionResource::convertBulk().
     *
     * @param list<array{from: string, to: string, amount: float|int, via?: string, date?: string}> $conversions
     * @param RequestConfig $config
     * @return array<string, mixed>
     */
    public function convertBulk(array $conversions, array $config = []): array
    {
        return $this->conversion->convertBulk($conversions, $config);
    }

    /**
     * The country's rate catalog (no prices). Growth+.
     *
     * @param RequestConfig $config
     * @return array<string, mixed>
     */
    public function markets(string $country, array $config = []): array
    {
        return $this->fx->markets($country, $config);
    }

    /**
     * Spread of the official rate against another market. Growth+.
     *
     * @param array{against?: string, base?: string} $options
     * @param RequestConfig $config
     * @return array<string, mixed>
     */
    public function spread(string $country, array $options = [], array $config = []): array
    {
        return $this->fx->spread($country, $options, $config);
    }

    /**
     * Best executable rate for an amount. Pro+.
     *
     * @param array{from: string, to: string, amount: float|int, direction?: string, maxStaleness?: int} $options
     * @param RequestConfig $config
     * @return array<string, mixed>
     */
    public function route(string $country, array $options, array $config = []): array
    {
        return $this->fx->route($country, $options, $config);
    }

    /**
     * BCV per-bank quotations (all plans).
     *
     * @param array{bank?: string} $options
     * @param RequestConfig $config
     * @return array<string, mixed>
     */
    public function bankRates(string $country, array $options = [], array $config = []): array
    {
        return $this->fx->bankRates($country, $options, $config);
    }

    /**
     * Low-level request with full response metadata (status, headers, rate-limit
     * info) and support for conditional GETs (ifNoneMatch) and non-JSON bodies
     * (parse => "text"). Most code should use the typed resources above.
     *
     * @param array{query?: array<string, scalar|null>, body?: array<string, mixed>, timeout?: float|int, maxRetries?: int, headers?: array<string, string>, language?: string, maxResponseBytes?: int, ifNoneMatch?: string, accept?: string, parse?: 'json'|'text'} $options
     */
    public function request(string $method, string $path, array $options = []): RawResult
    {
        $config = [];
        foreach (['timeout', 'maxRetries', 'headers', 'language'] as $k) {
            if (isset($options[$k])) {
                $config[$k] = $options[$k];
            }
        }
        $extra = [];
        foreach (['maxResponseBytes', 'ifNoneMatch', 'accept', 'parse'] as $k) {
            if (isset($options[$k])) {
                $extra[$k] = $options[$k];
            }
        }

        /** @var RequestConfig $config */
        /** @var array{maxResponseBytes?: int, ifNoneMatch?: string, accept?: string, parse?: 'json'|'text'} $extra */
        return $this->transport->request(
            $method,
            $path,
            $options['query'] ?? [],
            $options['body'] ?? null,
            $config,
            $extra,
        );
    }

    /**
     * Fetch a resource as CSV or XML and return the raw body text.
     *
     * @param array<string, scalar|null> $query
     */
    public function export(string $path, string $format, array $query = []): string
    {
        if ($format !== 'csv' && $format !== 'xml') {
            throw new \InvalidArgumentException("export: format must be 'csv' or 'xml'.");
        }
        $accept = $format === 'csv' ? 'text/csv' : 'application/xml';
        $result = $this->transport->request(
            'GET',
            $path,
            array_merge($query, ['format' => $format]),
            null,
            [],
            ['accept' => $accept, 'parse' => 'text'],
        );

        return is_string($result->data) ? $result->data : '';
    }
}
