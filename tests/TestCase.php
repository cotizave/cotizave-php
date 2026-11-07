<?php

declare(strict_types=1);

namespace Cotizave\Tests;

use Cotizave\Cotizave;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\Http\Message\RequestInterface;

abstract class TestCase extends BaseTestCase
{
    /** @var list<array{request: RequestInterface, response?: mixed, error?: mixed, options: array<string, mixed>}> */
    protected array $history = [];

    protected MockHandler $mock;

    /**
     * Build a client whose HTTP layer is a Guzzle MockHandler.
     *
     * @param list<\Psr\Http\Message\ResponseInterface|\Throwable> $responses
     * @param array<string, mixed>                                 $options
     */
    protected function client(array $responses, array $options = []): Cotizave
    {
        $this->mock = new MockHandler($responses);
        $stack = HandlerStack::create($this->mock);
        $this->history = [];
        $stack->push(Middleware::history($this->history));
        $guzzle = new Client(['handler' => $stack]);

        /** @var array{apiKey?: string, httpClient?: \GuzzleHttp\ClientInterface} $merged */
        $merged = array_merge(['apiKey' => 'ctz_test_key', 'httpClient' => $guzzle], $options);

        return new Cotizave($merged);
    }

    protected function lastRequest(): RequestInterface
    {
        $entry = end($this->history);
        self::assertIsArray($entry, 'No request was recorded.');

        return $entry['request'];
    }

    protected function requestCount(): int
    {
        return count($this->history);
    }
}
