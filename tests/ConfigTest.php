<?php

declare(strict_types=1);

namespace Cotizave\Tests;

use Cotizave\Cotizave;
use PHPUnit\Framework\TestCase as BaseTestCase;

final class ConfigTest extends BaseTestCase
{
    public function testRequiresApiKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        putenv('COTIZAVE_API_KEY'); // unset
        new Cotizave(['apiKey' => '']);
    }

    public function testReadsApiKeyFromEnv(): void
    {
        putenv('COTIZAVE_API_KEY=ctz_env_key');
        try {
            $client = new Cotizave();
            self::assertSame('https://api.cotizave.com', $client->config['baseUrl']);
        } finally {
            putenv('COTIZAVE_API_KEY');
        }
    }

    public function testPublicConfigHidesApiKey(): void
    {
        $client = new Cotizave(['apiKey' => 'ctz_secret']);
        self::assertArrayNotHasKey('apiKey', $client->config);
        self::assertStringNotContainsString('ctz_secret', var_export($client->config, true));
    }

    public function testHttpBaseUrlRequiresOptIn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Cotizave(['apiKey' => 'x', 'baseUrl' => 'http://localhost:8080']);
    }

    public function testHttpBaseUrlAllowedWithOptIn(): void
    {
        $client = new Cotizave(['apiKey' => 'x', 'baseUrl' => 'http://localhost:8080', 'allowInsecureHttp' => true]);
        self::assertSame('http://localhost:8080', $client->config['baseUrl']);
    }

    public function testInvalidBaseUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Cotizave(['apiKey' => 'x', 'baseUrl' => 'not a url']);
    }

    public function testBaseUrlTrailingSlashStripped(): void
    {
        $client = new Cotizave(['apiKey' => 'x', 'baseUrl' => 'https://api.cotizave.com/']);
        self::assertSame('https://api.cotizave.com', $client->config['baseUrl']);
    }

    public function testReservedHeaderRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Cotizave(['apiKey' => 'x', 'headers' => ['X-API-Key' => 'sneaky']]);
    }

    public function testControlCharactersInHeaderRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Cotizave(['apiKey' => 'x', 'headers' => ['X-Trace' => "a\r\nInjected: y"]]);
    }

    public function testInvalidHeaderNameRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Cotizave(['apiKey' => 'x', 'headers' => ['bad header' => 'v']]);
    }

    public function testNegativeTimeoutRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Cotizave(['apiKey' => 'x', 'timeout' => -1]);
    }

    public function testUserAgentAppended(): void
    {
        $client = new Cotizave(['apiKey' => 'x', 'userAgent' => 'acme/1.2.3']);
        $ua = $client->config['userAgent'];
        self::assertIsString($ua);
        self::assertStringStartsWith('cotizave-php/', $ua);
        self::assertStringEndsWith('acme/1.2.3', $ua);
    }
}
