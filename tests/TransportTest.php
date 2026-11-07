<?php

declare(strict_types=1);

namespace Cotizave\Tests;

use Cotizave\Exception\AuthenticationException;
use Cotizave\Exception\BadRequestException;
use Cotizave\Exception\ConnectionException;
use Cotizave\Exception\CotizaveException;
use Cotizave\Exception\FeatureNotInPlanException;
use Cotizave\Exception\ForbiddenException;
use Cotizave\Exception\NotAcceptableException;
use Cotizave\Exception\NotFoundException;
use Cotizave\Exception\RateLimitException;
use Cotizave\Exception\ServerException;
use Cotizave\Exception\TimeoutException;
use Cotizave\Exception\UnprocessableException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;

final class TransportTest extends TestCase
{
    public function testSuccessReturnsParsedJson(): void
    {
        $client = $this->client([new Response(200, [], (string) json_encode(['id' => 'co-trm', 'mid' => 3950.5]))]);
        $out = $client->rates->get('CO', 'co-trm');
        self::assertSame(3950.5, $out['mid']);
    }

    public function testAuthHeaderAndUserAgentSent(): void
    {
        $client = $this->client([new Response(200, [], (string) json_encode(['rates' => []]))]);
        $client->rates->list('AR');
        $req = $this->lastRequest();
        self::assertSame('ctz_test_key', $req->getHeaderLine('X-API-Key'));
        self::assertStringStartsWith('cotizave-php/', $req->getHeaderLine('User-Agent'));
        self::assertSame('application/json', $req->getHeaderLine('Accept'));
    }

    public function testQueryParamsSerialized(): void
    {
        $client = $this->client([new Response(200, [], (string) json_encode(['rates' => []]))]);
        $client->rates->list('AR', ['tag' => 'bcv', 'base' => 'USD']);
        $url = (string) $this->lastRequest()->getUri();
        self::assertStringContainsString('tag=bcv', $url);
        self::assertStringContainsString('base=USD', $url);
    }

    public function testNullQueryParamsOmitted(): void
    {
        $client = $this->client([new Response(200, [], (string) json_encode(['rates' => []]))]);
        $client->rates->list('AR');
        self::assertStringNotContainsString('tag=', (string) $this->lastRequest()->getUri());
    }

    /**
     * @return list<array{int, class-string<CotizaveException>, string}>
     */
    public static function errorStatuses(): array
    {
        return [
            [400, BadRequestException::class, 'bad_request'],
            [401, AuthenticationException::class, 'invalid_api_key'],
            [402, FeatureNotInPlanException::class, 'feature_not_in_plan'],
            [403, ForbiddenException::class, 'forbidden'],
            [404, NotFoundException::class, 'rate_not_found'],
            [406, NotAcceptableException::class, 'not_acceptable'],
            [422, UnprocessableException::class, 'no_reference'],
            [500, ServerException::class, 'server_error'],
        ];
    }

    /**
     * @param class-string<CotizaveException> $expected
     */
    #[DataProvider('errorStatuses')]
    public function testErrorStatusMapsToSubclass(int $status, string $expected, string $code): void
    {
        $client = $this->client([new Response($status, [], (string) json_encode(['code' => $code, 'message' => 'nope']))]);
        try {
            $client->rates->get('CO', 'x', ['maxRetries' => 0]);
            self::fail('expected exception');
        } catch (CotizaveException $e) {
            self::assertInstanceOf($expected, $e);
            self::assertSame($status, $e->status);
            self::assertSame($code, $e->errorCode);
            self::assertSame('nope', $e->getMessage());
        }
    }

    public function testErrorFieldAndDetails(): void
    {
        $body = ['code' => 'unknown_parameter', 'message' => 'bad', 'field' => 'base', 'details' => ['k' => 1]];
        $client = $this->client([new Response(400, [], (string) json_encode($body))]);
        try {
            $client->rates->get('CO', 'x', ['maxRetries' => 0]);
            self::fail('expected exception');
        } catch (BadRequestException $e) {
            self::assertSame('base', $e->field);
            self::assertSame(['k' => 1], $e->details);
        }
    }

    public function testNonJsonErrorBodyKept(): void
    {
        $client = $this->client([new Response(502, [], '<html>Bad Gateway</html>')]);
        try {
            $client->rates->get('CO', 'x', ['maxRetries' => 0]);
            self::fail('expected exception');
        } catch (ServerException $e) {
            self::assertArrayHasKey('non_json_body', $e->details ?? []);
        }
    }

    public function testRateLimitScopeAndRetryAfter(): void
    {
        $client = $this->client([new Response(429, ['Retry-After' => '30', 'X-RateLimit-Scope' => 'monthly'], (string) json_encode(['code' => 'rate_limit_exceeded', 'message' => 'quota', 'details' => ['kind' => 'monthly']]))]);
        try {
            $client->rates->get('CO', 'x', ['maxRetries' => 0]);
            self::fail('expected exception');
        } catch (RateLimitException $e) {
            self::assertSame('monthly', $e->scope());
            self::assertTrue($e->isQuotaExhausted());
            self::assertSame(30, $e->retryAfter());
        }
    }

    public function test304NotModified(): void
    {
        $client = $this->client([new Response(304)]);
        $result = $client->request('GET', '/v2/fx/co/rates/co-trm', ['ifNoneMatch' => '"etag"']);
        self::assertTrue($result->notModified);
        self::assertNull($result->data);
    }

    public function testResponseTooLarge(): void
    {
        $client = $this->client([new Response(200, [], str_repeat('x', 1000))], ['maxResponseBytes' => 10]);
        try {
            $client->rates->get('CO', 'x', ['maxRetries' => 0]);
            self::fail('expected exception');
        } catch (CotizaveException $e) {
            self::assertSame('response_too_large', $e->errorCode);
        }
    }

    public function testOriginPinningRejectsOffHost(): void
    {
        $client = $this->client([]);
        try {
            $client->request('GET', '@evil.com/steal');
            self::fail('expected exception');
        } catch (CotizaveException $e) {
            self::assertSame('invalid_request_path', $e->errorCode);
        }
        self::assertSame(0, $this->requestCount());
    }

    public function testInvalidJsonSuccessBody(): void
    {
        $client = $this->client([new Response(200, ['Content-Type' => 'application/json'], 'not json {')]);
        try {
            $client->rates->get('CO', 'x', ['maxRetries' => 0]);
            self::fail('expected exception');
        } catch (CotizaveException $e) {
            self::assertSame('invalid_json_response', $e->errorCode);
        }
    }

    public function testTimeoutMapsToTimeoutException(): void
    {
        $client = $this->client([new ConnectException('cURL error 28: Operation timed out', new Request('GET', '/'))]);
        $this->expectException(TimeoutException::class);
        $client->rates->get('CO', 'x', ['maxRetries' => 0]);
    }

    public function testConnectionErrorMaps(): void
    {
        $client = $this->client([new ConnectException('could not resolve host', new Request('GET', '/'))]);
        try {
            $client->rates->get('CO', 'x', ['maxRetries' => 0]);
            self::fail('expected exception');
        } catch (ConnectionException $e) {
            self::assertSame('connection_error', $e->errorCode);
        }
    }

    public function testRedirectRefused(): void
    {
        $client = $this->client([new Response(302, ['Location' => 'https://evil.com'])]);
        try {
            $client->rates->get('CO', 'x', ['maxRetries' => 0]);
            self::fail('expected exception');
        } catch (CotizaveException $e) {
            self::assertSame('unexpected_redirect', $e->errorCode);
        }
        // The redirect was never followed: only the first request was made.
        self::assertSame(1, $this->requestCount());
    }
}
