<?php

declare(strict_types=1);

namespace Cotizave\Tests\Fixtures;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;

/**
 * A response body stream that fails on read — simulates a transport read error
 * mid-body (e.g. a cURL "Unable to read from stream" when the read times out).
 */
final class ThrowingStream implements StreamInterface
{
    use StreamDecoratorTrait;

    public function __construct(private StreamInterface $stream)
    {
    }

    public function eof(): bool
    {
        return false;
    }

    public function read(int $length): string
    {
        throw new \RuntimeException('Unable to read from stream');
    }
}
