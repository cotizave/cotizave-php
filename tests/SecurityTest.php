<?php

declare(strict_types=1);

namespace Cotizave\Tests;

use Cotizave\Cotizave;
use Cotizave\Exception\ConnectionException;
use Cotizave\Exception\CotizaveException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

final class SecurityTest extends TestCase
{
    public function testRedirectNotFollowedEvenWithPermissiveClient(): void
    {
        // A client whose default is to FOLLOW redirects must still not be allowed
        // to forward the X-API-Key header to a redirect target: the transport
        // forces allow_redirects=false on every request.
        $mock = new MockHandler([
            new Response(302, ['Location' => 'https://evil.com/steal']),
            new Response(200, [], (string) json_encode(['ok' => true])), // must NEVER be reached
        ]);
        $stack = HandlerStack::create($mock);
        $permissive = new Client(['handler' => $stack, 'allow_redirects' => true]);
        $client = new Cotizave(['apiKey' => 'ctz_secret', 'httpClient' => $permissive]);

        try {
            $client->rates->get('CO', 'x', ['maxRetries' => 0]);
            self::fail('expected a refused redirect');
        } catch (CotizaveException $e) {
            self::assertSame('unexpected_redirect', $e->errorCode);
        }

        // The second (evil) response was never consumed — the key never left.
        self::assertSame(1, $mock->count());
    }

    public function testApiKeyNotInExceptionMessage(): void
    {
        $client = $this->client([new ConnectException('could not resolve host api.cotizave.com', new Request('GET', '/'))]);
        try {
            $client->rates->get('CO', 'x', ['maxRetries' => 0]);
            self::fail('expected exception');
        } catch (ConnectionException $e) {
            self::assertStringNotContainsString('ctz_test_key', $e->getMessage());
        }
    }

    public function testApiKeyNeverInQueryString(): void
    {
        $client = $this->client([new Response(200, [], (string) json_encode(['rates' => []]))]);
        $client->rates->list('AR', ['tag' => 'bcv']);
        $req = $this->lastRequest();
        self::assertStringNotContainsString('ctz_test_key', (string) $req->getUri());
        self::assertSame('ctz_test_key', $req->getHeaderLine('X-API-Key'));
    }
}
