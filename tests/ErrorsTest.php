<?php

declare(strict_types=1);

namespace Cotizave\Tests;

use Cotizave\Exception\AuthenticationException;
use Cotizave\Exception\CotizaveException;
use Cotizave\Exception\NotFoundException;
use Cotizave\Exception\RateLimitException;
use Cotizave\Exception\ServerException;
use Cotizave\RateLimitInfo;
use Cotizave\ResponseMeta;
use PHPUnit\Framework\TestCase as BaseTestCase;

final class ErrorsTest extends BaseTestCase
{
    private function meta(int $status = 400, ?RateLimitInfo $rl = null): ResponseMeta
    {
        return new ResponseMeta($status, [], $rl ?? new RateLimitInfo());
    }

    public function testFromResponseUsesApiCode(): void
    {
        $err = CotizaveException::fromResponse(401, ['code' => 'invalid_api_key', 'message' => 'bad'], $this->meta(401));
        self::assertInstanceOf(AuthenticationException::class, $err);
        self::assertSame('invalid_api_key', $err->errorCode);
        self::assertSame('bad', $err->getMessage());
    }

    public function testFromResponseFallbackCode(): void
    {
        $err = CotizaveException::fromResponse(404, null, $this->meta(404));
        self::assertInstanceOf(NotFoundException::class, $err);
        self::assertSame('resource_not_found', $err->errorCode);
    }

    public function testFromResponseServerFallback(): void
    {
        $err = CotizaveException::fromResponse(503, null, $this->meta(503));
        self::assertInstanceOf(ServerException::class, $err);
        self::assertSame('server_error', $err->errorCode);
    }

    public function testRateLimitScopeFromDetails(): void
    {
        $err = CotizaveException::fromResponse(429, ['code' => 'rate_limit_exceeded', 'message' => 'x', 'details' => ['kind' => 'burst']], $this->meta(429));
        self::assertInstanceOf(RateLimitException::class, $err);
        self::assertSame('burst', $err->scope());
        self::assertFalse($err->isQuotaExhausted());
    }

    public function testRateLimitScopeFromHeaders(): void
    {
        $meta = $this->meta(429, new RateLimitInfo(scope: 'monthly', retryAfter: 60));
        $err = CotizaveException::fromResponse(429, ['code' => 'rate_limit_exceeded', 'message' => 'x'], $meta);
        self::assertInstanceOf(RateLimitException::class, $err);
        self::assertSame('monthly', $err->scope());
        self::assertSame(60, $err->retryAfter());
        self::assertTrue($err->isQuotaExhausted());
    }

    public function testNonJsonBodyCapturedInDetails(): void
    {
        $err = CotizaveException::fromResponse(502, null, $this->meta(502), '<html>oops</html>');
        self::assertSame(['non_json_body' => '<html>oops</html>'], $err->details);
    }
}
