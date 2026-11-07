<?php

declare(strict_types=1);

namespace Cotizave\Tests;

use Cotizave\Cotizave;
use GuzzleHttp\Handler\MockHandler;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\Http\Message\RequestInterface;

abstract class TestCase extends BaseTestCase
{
    protected MockHandler $mock;

    private int $queued = 0;

    /**
     * Build a client whose Guzzle base handler is a MockHandler, so the SDK's own
     * retry middleware (on the HandlerStack Transport builds) drives retries over
     * the queued responses — exactly as it would in production.
     *
     * @param list<\Psr\Http\Message\ResponseInterface|\Throwable> $responses
     * @param array<string, mixed>                                 $options
     */
    protected function client(array $responses, array $options = []): Cotizave
    {
        $this->mock = new MockHandler($responses);
        $this->queued = count($responses);

        /** @var array{apiKey?: string, handler?: callable} $merged */
        $merged = array_merge(['apiKey' => 'ctz_test_key', 'handler' => $this->mock], $options);

        return new Cotizave($merged);
    }

    protected function lastRequest(): RequestInterface
    {
        $request = $this->mock->getLastRequest();
        self::assertNotNull($request, 'No request was recorded.');

        return $request;
    }

    /** Number of HTTP requests actually sent (initial attempt + retries). */
    protected function requestCount(): int
    {
        return $this->queued - $this->mock->count();
    }
}
