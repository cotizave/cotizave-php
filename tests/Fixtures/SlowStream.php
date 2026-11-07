<?php

declare(strict_types=1);

namespace Cotizave\Tests\Fixtures;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;

/**
 * A response body stream that never ends and trickles a byte per read with a
 * small delay — simulates a slow / stalling body that a read deadline must cut
 * off instead of letting it hang.
 */
final class SlowStream implements StreamInterface
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
        usleep(50_000); // 50 ms per read

        return ' ';
    }
}
