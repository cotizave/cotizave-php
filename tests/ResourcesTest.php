<?php

declare(strict_types=1);

namespace Cotizave\Tests;

use GuzzleHttp\Psr7\Response;

final class ResourcesTest extends TestCase
{
    private function ok(mixed $body): Response
    {
        return new Response(200, [], (string) json_encode($body));
    }

    private function url(): string
    {
        return (string) $this->lastRequest()->getUri();
    }

    public function testRatesList(): void
    {
        $client = $this->client([$this->ok(['rates' => []])]);
        $client->rates->list('CO');
        self::assertStringEndsWith('/v2/fx/co/rates', $this->lastRequest()->getUri()->getPath());
    }

    public function testRatesGetEncodesSegment(): void
    {
        $client = $this->client([$this->ok(['id' => 'co-trm'])]);
        $client->rates->get('CO', 'co-trm');
        self::assertSame('/v2/fx/co/rates/co-trm', $this->lastRequest()->getUri()->getPath());
    }

    public function testRatesSnapshotUsesDate(): void
    {
        $client = $this->client([$this->ok(['rates' => []])]);
        $client->rates->snapshot('AR', '2026-10-01');
        self::assertStringContainsString('date=2026-10-01', $this->url());
    }

    public function testRatesGlobalJoinsSymbols(): void
    {
        $client = $this->client([$this->ok(['rates' => []])]);
        $client->rates->global(['symbols' => ['BRL', 'EUR']]);
        self::assertStringContainsString('symbols=BRL%2CEUR', $this->url());
    }

    public function testCountryCodeNormalizedToLower(): void
    {
        $client = $this->client([$this->ok(['rates' => []])]);
        $client->rates->list('VE');
        self::assertSame('/v2/fx/ve/rates', $this->lastRequest()->getUri()->getPath());
    }

    public function testConvertRequiresPositiveAmount(): void
    {
        $client = $this->client([]);
        $this->expectException(\InvalidArgumentException::class);
        $client->convert(['from' => 'USD', 'to' => 'BRL', 'amount' => 0]);
    }

    public function testConvertBuildsQuery(): void
    {
        $client = $this->client([$this->ok(['rate' => 5.0])]);
        $client->convert(['from' => 'USD', 'to' => 'BRL', 'amount' => 100]);
        $url = $this->url();
        self::assertStringContainsString('from=USD', $url);
        self::assertStringContainsString('to=BRL', $url);
        self::assertStringContainsString('amount=100', $url);
    }

    public function testConvertBulkPostsBody(): void
    {
        $client = $this->client([$this->ok(['count' => 1, 'results' => []])]);
        $client->convertBulk([['from' => 'USD', 'to' => 'ARS', 'amount' => 1]]);
        $req = $this->lastRequest();
        self::assertSame('POST', $req->getMethod());
        self::assertSame('application/json', $req->getHeaderLine('Content-Type'));
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $req->getBody(), true);
        self::assertSame([['from' => 'USD', 'to' => 'ARS', 'amount' => 1]], $body['conversions']);
    }

    public function testConvertBulkEmptyRejected(): void
    {
        $client = $this->client([]);
        $this->expectException(\InvalidArgumentException::class);
        $client->convertBulk([]);
    }

    public function testConvertBulkTooManyRejected(): void
    {
        $client = $this->client([]);
        $this->expectException(\InvalidArgumentException::class);
        $client->convertBulk(array_fill(0, 51, ['from' => 'USD', 'to' => 'ARS', 'amount' => 1]));
    }

    public function testHistoryDailyQuery(): void
    {
        $client = $this->client([$this->ok(['points' => []])]);
        $client->history->daily('AR', 'ar-official', ['from' => '2026-09-01', 'to' => '2026-09-30']);
        $url = $this->url();
        self::assertStringContainsString('granularity=daily', $url);
        self::assertStringContainsString('from=2026-09-01', $url);
        self::assertStringContainsString('to=2026-09-30', $url);
    }

    public function testHistoryIterateFollowsCursor(): void
    {
        $client = $this->client([
            $this->ok(['points' => [['date' => '2026-01-01']], 'next_cursor' => 'c2']),
            $this->ok(['points' => [['date' => '2026-01-02']], 'next_cursor' => null]),
        ]);
        $dates = [];
        foreach ($client->history->iterate('VE', 've-bcv') as $point) {
            $dates[] = $point['date'];
        }
        self::assertSame(['2026-01-01', '2026-01-02'], $dates);
    }

    public function testHistoryIterateRespectsMaxPages(): void
    {
        $client = $this->client([
            $this->ok(['points' => [['date' => 'd']], 'next_cursor' => 'more']),
            $this->ok(['points' => [['date' => 'd']], 'next_cursor' => 'more']),
            $this->ok(['points' => [['date' => 'd']], 'next_cursor' => 'more']),
        ]);
        $points = iterator_to_array($client->history->iterate('VE', 've-bcv', ['maxPages' => 3]), false);
        self::assertCount(3, $points);
        self::assertSame(3, $this->requestCount());
    }

    public function testMarketsSpreadRouteBankRates(): void
    {
        $client = $this->client([
            $this->ok(['markets' => []]),
            $this->ok([]),
            $this->ok([]),
            $this->ok(['banks' => []]),
        ]);
        $client->markets('VE');
        self::assertSame('/v2/fx/ve/markets', $this->lastRequest()->getUri()->getPath());
        $client->spread('VE', ['against' => 've-paralelo']);
        self::assertStringContainsString('against=ve-paralelo', $this->url());
        $client->route('VE', ['from' => 'USD', 'to' => 'VES', 'amount' => 100]);
        self::assertStringContainsString('amount=100', $this->url());
        $client->bankRates('VE', ['bank' => '0102']);
        self::assertStringContainsString('bank=0102', $this->url());
    }

    public function testCatalogs(): void
    {
        $client = $this->client([
            $this->ok(['countries' => []]),
            $this->ok(['currencies' => []]),
            $this->ok(['banks' => []]),
            $this->ok(['issuers' => []]),
        ]);
        self::assertSame(['countries' => []], $client->countries->list());
        self::assertSame(['currencies' => []], $client->currencies->list());
        self::assertSame(['banks' => []], $client->banks->list('VE', ['q' => 'banesco']));
        self::assertStringContainsString('q=banesco', $this->url());
        self::assertSame(['issuers' => []], $client->issuers->list());
    }

    public function testHolidays(): void
    {
        $client = $this->client([
            $this->ok(['country' => 'AR', 'year' => 2026, 'holidays' => []]),
            $this->ok(['result' => '2026-10-08']),
        ]);
        $client->holidays->list('AR', ['year' => 2026]);
        self::assertStringContainsString('year=2026', $this->url());
        $client->holidays->businessDays('VE', ['from' => '2026-10-01', 'days' => 5]);
        self::assertStringContainsString('days=5', $this->url());
    }

    public function testBusinessDaysValidates(): void
    {
        $client = $this->client([]);
        $this->expectException(\InvalidArgumentException::class);
        $client->holidays->businessDays('VE', ['days' => -1]);
    }

    public function testCrypto(): void
    {
        $client = $this->client([$this->ok(['rates' => []]), $this->ok(['id' => 'usdt-usd'])]);
        $client->crypto->list(['tag' => 'usdt']);
        self::assertStringContainsString('tag=usdt', $this->url());
        $client->crypto->get('usdt-usd');
        self::assertSame('/v2/crypto/usdt-usd', $this->lastRequest()->getUri()->getPath());
    }

    public function testExportCsv(): void
    {
        $client = $this->client([new Response(200, [], "id,mid\nar-official,1000")]);
        $out = $client->export('/v2/fx/ar/rates', 'csv');
        self::assertStringStartsWith('id,mid', $out);
        self::assertSame('text/csv', $this->lastRequest()->getHeaderLine('Accept'));
        self::assertStringContainsString('format=csv', $this->url());
    }

    public function testExportRejectsBadFormat(): void
    {
        $client = $this->client([]);
        $this->expectException(\InvalidArgumentException::class);
        $client->export('/v2/fx/ar/rates', 'pdf');
    }

    public function testPerCallLanguageAndHeaders(): void
    {
        $client = $this->client([$this->ok(['rates' => []])]);
        $client->rates->list('AR', [], ['language' => 'es', 'headers' => ['X-Trace' => 'abc']]);
        $req = $this->lastRequest();
        self::assertSame('es', $req->getHeaderLine('Accept-Language'));
        self::assertSame('abc', $req->getHeaderLine('X-Trace'));
    }
}
