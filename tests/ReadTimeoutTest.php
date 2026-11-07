<?php

declare(strict_types=1);

namespace Cotizave\Tests;

use Cotizave\Exception\ConnectionException;
use Cotizave\Exception\CotizaveException;
use Cotizave\Exception\TimeoutException;
use Cotizave\Tests\Fixtures\SlowStream;
use Cotizave\Tests\Fixtures\ThrowingStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;

final class ReadTimeoutTest extends TestCase
{
    public function testBodyReadErrorIsTypedNotRaw(): void
    {
        // A failure while reading the streamed body must surface as an SDK
        // exception (typed, and therefore retryable), never as a raw
        // \RuntimeException leaking out of the client.
        $client = $this->client([new Response(200, [], new ThrowingStream(Utils::streamFor('')))]);
        try {
            $client->rates->get('CO', 'x', ['maxRetries' => 0]);
            self::fail('expected a typed SDK exception');
        } catch (CotizaveException $e) {
            self::assertInstanceOf(ConnectionException::class, $e);
            self::assertSame('connection_error', $e->errorCode);
        }
    }

    public function testStallingBodyIsCutOffByTheReadDeadline(): void
    {
        // A body that never ends must be bounded by the per-request timeout and
        // raise TimeoutException — not hang the caller.
        $client = $this->client([new Response(200, [], new SlowStream(Utils::streamFor('')))], ['timeout' => 0.1]);
        $start = microtime(true);
        try {
            $client->rates->get('CO', 'x', ['maxRetries' => 0]);
            self::fail('expected a timeout');
        } catch (TimeoutException $e) {
            self::assertSame('timeout', $e->errorCode);
        }
        self::assertLessThan(2.0, microtime(true) - $start, 'the read was not bounded by the timeout');
    }
}
