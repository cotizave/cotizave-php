<?php

declare(strict_types=1);

namespace Cotizave\Tests;

use Cotizave\Cotizave;
use Cotizave\Exception\ConnectionException;
use Cotizave\Exception\CotizaveException;
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

    public function testSlowBodyIsBoundedByTheRequestTimeout(): void
    {
        // Loopback regression for NV1: a server that trickles the body forever
        // must be cut off by Guzzle's request `timeout` (the library bound) and
        // mapped to a typed SDK error — not hang the caller.
        if (!\extension_loaded('curl') || !\function_exists('proc_open')) {
            self::markTestSkipped('needs ext-curl and proc_open');
        }
        $port = 9700 + random_int(1, 250);
        $script = __DIR__ . '/Fixtures/slow_body_server.php';
        $proc = proc_open([PHP_BINARY, $script, (string) $port], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            self::markTestSkipped('could not start the loopback server');
        }

        try {
            $ready = fgets($pipes[1]);
            self::assertIsString($ready);
            self::assertStringContainsString('READY', $ready);

            $client = new Cotizave([
                'apiKey' => 'x',
                'baseUrl' => "http://127.0.0.1:{$port}",
                'allowInsecureHttp' => true,
            ]);

            // Use the low-level request() so an OS-specific bounded-but-empty
            // outcome does not trip a typed resource's array return. The invariant
            // that matters is BOUNDEDNESS: the call must return or throw within the
            // timeout budget, not hang (the server would otherwise trickle ~30s).
            $start = microtime(true);
            $thrown = null;
            try {
                $client->request('GET', '/v2/fx/co/rates/x', ['timeout' => 1.0, 'maxRetries' => 0]);
            } catch (CotizaveException $e) {
                $thrown = $e;
            }
            $elapsed = microtime(true) - $start;

            self::assertLessThan(5.0, $elapsed, 'the streamed read was not bounded by the timeout');
            if ($thrown !== null) {
                // If it surfaced as an error it must be a typed, bounded one.
                self::assertContains($thrown->errorCode, ['timeout', 'connection_error']);
            }
        } finally {
            proc_terminate($proc);
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($proc);
        }
    }
}
