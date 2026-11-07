<?php

declare(strict_types=1);

namespace Cotizave\Tests;

use Cotizave\Exception\RateLimitException;
use Cotizave\Exception\ServerException;
use Cotizave\ResponseEvent;
use Cotizave\RetryContext;
use Cotizave\RetryEvent;
use GuzzleHttp\Psr7\Response;

final class RetryTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $noDelay = ['retry' => ['baseDelay' => 0, 'maxDelay' => 0]];

    private function serverError(): Response
    {
        return new Response(500, [], (string) json_encode(['code' => 'server_error', 'message' => 'x']));
    }

    public function testRetries5xxThenSucceeds(): void
    {
        $client = $this->client([$this->serverError(), new Response(200, [], (string) json_encode(['mid' => 1.5]))], $this->noDelay);
        $out = $client->rates->get('CO', 'x');
        self::assertSame(1.5, $out['mid']);
        self::assertSame(2, $this->requestCount());
    }

    public function testStopsAfterMaxRetries(): void
    {
        $client = $this->client([$this->serverError(), $this->serverError(), $this->serverError()], $this->noDelay);
        try {
            $client->rates->get('CO', 'x', ['maxRetries' => 2]);
            self::fail('expected exception');
        } catch (ServerException) {
            self::assertSame(3, $this->requestCount());
        }
    }

    public function testMaxRetriesZeroDisables(): void
    {
        $client = $this->client([$this->serverError()], $this->noDelay);
        try {
            $client->rates->get('CO', 'x', ['maxRetries' => 0]);
            self::fail('expected exception');
        } catch (ServerException) {
            self::assertSame(1, $this->requestCount());
        }
    }

    public function testBurst429IsRetried(): void
    {
        $client = $this->client([
            new Response(429, ['Retry-After' => '0', 'X-RateLimit-Scope' => 'burst'], (string) json_encode(['code' => 'rate_limit_exceeded', 'message' => 'slow', 'details' => ['kind' => 'burst']])),
            new Response(200, [], (string) json_encode(['mid' => 1.5])),
        ], $this->noDelay);
        $out = $client->rates->get('CO', 'x');
        self::assertSame(1.5, $out['mid']);
        self::assertSame(2, $this->requestCount());
    }

    public function testMonthly429IsNotRetried(): void
    {
        $client = $this->client([new Response(429, ['X-RateLimit-Scope' => 'monthly'], (string) json_encode(['code' => 'rate_limit_exceeded', 'message' => 'quota', 'details' => ['kind' => 'monthly']]))], $this->noDelay);
        try {
            $client->rates->get('CO', 'x');
            self::fail('expected exception');
        } catch (RateLimitException) {
            self::assertSame(1, $this->requestCount());
        }
    }

    public function testPostIsNotRetriedByDefault(): void
    {
        $client = $this->client([$this->serverError()], $this->noDelay);
        try {
            $client->convertBulk([['from' => 'USD', 'to' => 'ARS', 'amount' => 1]]);
            self::fail('expected exception');
        } catch (ServerException) {
            self::assertSame(1, $this->requestCount());
        }
    }

    public function testCustomShouldRetryOverrides(): void
    {
        $calls = 0;
        $options = ['retry' => ['baseDelay' => 0, 'maxDelay' => 0, 'shouldRetry' => function (RetryContext $ctx) use (&$calls): bool {
            $calls++;

            return false;
        }]];
        $client = $this->client([$this->serverError()], $options);
        try {
            $client->rates->get('CO', 'x');
            self::fail('expected exception');
        } catch (ServerException) {
            self::assertSame(1, $this->requestCount());
            self::assertSame(1, $calls);
        }
    }

    public function testOnRetryHookCalled(): void
    {
        $events = [];
        $options = ['retry' => ['baseDelay' => 0, 'maxDelay' => 0, 'onRetry' => function (RetryEvent $e) use (&$events): void {
            $events[] = $e;
        }]];
        $client = $this->client([$this->serverError(), new Response(200, [], (string) json_encode(['mid' => 1.5]))], $options);
        $client->rates->get('CO', 'x');
        self::assertCount(1, $events);
        self::assertSame(1, $events[0]->retryNumber);
        self::assertSame('/v2/fx/co/rates/x', $events[0]->path);
    }

    public function testOnResponseHookCalled(): void
    {
        $seen = [];
        $options = ['onResponse' => function (ResponseEvent $e) use (&$seen): void {
            $seen[] = $e->meta->status;
        }];
        $client = $this->client([new Response(200, [], (string) json_encode(['mid' => 1.5]))], $options);
        $client->rates->get('CO', 'x');
        self::assertSame([200], $seen);
    }
}
