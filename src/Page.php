<?php

declare(strict_types=1);

namespace Kirora;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * One page of a list. Iterating it yields this page's items; use the service's `all()` for
 * every item, fetching pages as it goes.
 *
 * For the next page yourself, pass `nextCursor()` back as the `cursor` query parameter,
 * with the same filters. It's null on the last page.
 *
 * @template T
 *
 * @implements IteratorAggregate<int, T>
 */
final class Page implements Countable, IteratorAggregate, JsonSerializable
{
    /**
     * @param  list<T>  $data  This page's items.
     * @param  array<string, mixed>  $meta  The page's `meta`: `pagination`, with `limit` and
     *   `nextCursor`, and anything else the list adds, such as the contacts list's `count`.
     *
     * @internal
     */
    public function __construct(
        public readonly array $data,
        public readonly array $meta,
        private readonly ?ResponseInfo $lastResponse = null,
    ) {
    }

    /**
     * The cursor for the next page, or null on the last.
     */
    public function nextCursor(): ?string
    {
        $cursor = $this->meta['pagination']['nextCursor'] ?? null;

        return is_string($cursor) ? $cursor : null;
    }

    public function hasMore(): bool
    {
        return $this->nextCursor() !== null;
    }

    /**
     * The HTTP response this page came from.
     */
    public function lastResponse(): ?ResponseInfo
    {
        return $this->lastResponse;
    }

    public function count(): int
    {
        return count($this->data);
    }

    /**
     * @return Traversable<int, T>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->data);
    }

    /**
     * @return array{data: list<mixed>, meta: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'data' => array_map(fn ($item) => $item instanceof Resource ? $item->toArray() : $item, $this->data),
            'meta' => $this->meta,
        ];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
