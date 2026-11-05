<?php

declare(strict_types=1);

namespace Kirora\Transporters;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/**
 * A response body, held up to `$limit` bytes. A write past the limit returns 0, which stops
 * the transfer as Guzzle writes it in. The limit is on the body as decoded, so a compressed
 * body can't get round it.
 *
 * @internal
 */
final class BoundedSink implements StreamInterface
{
    use StreamDecoratorTrait;

    private bool $overflowed = false;

    private StreamInterface $stream;

    public function __construct(public readonly int $limit)
    {
        $this->stream = Utils::streamFor('');
    }

    public function overflowed(): bool
    {
        return $this->overflowed;
    }

    /**
     * Marks the body too large before any of it arrives, as from its Content-Length.
     */
    public function overflow(): void
    {
        $this->overflowed = true;
    }

    /**
     * The body, or null when it was larger than the limit.
     */
    public function contents(): ?string
    {
        if ($this->overflowed) {
            return null;
        }
        $this->stream->rewind();

        return $this->stream->getContents();
    }

    public function write($string): int
    {
        // Nothing more is kept once the body is over the limit.
        if ($this->overflowed || strlen($string) > $this->limit - (int) $this->stream->getSize()) {
            $this->overflowed = true;

            return 0;
        }

        return $this->stream->write($string);
    }
}
