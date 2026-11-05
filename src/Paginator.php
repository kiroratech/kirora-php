<?php

declare(strict_types=1);

namespace Kirora;

use Generator;
use Kirora\Contracts\Transporter;
use Kirora\Exceptions\UnserializableResponse;
use Kirora\ValueObjects\Transporter\Payload;

/**
 * Fetches pages of a list.
 *
 * @internal
 */
final class Paginator
{
    /**
     * One page, with each item as a `$class`, or as decoded when it's null.
     *
     * @template T of Resource
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $options
     * @param  class-string<T>|null  $class
     * @return ($class is null ? Page<array<string, mixed>> : Page<T>)
     */
    public static function page(Transporter $transporter, string $path, array $query, array $options, ?string $class): Page
    {
        return self::fetch($transporter, Payload::fromPath('GET', $path, null, $options, Payload::FORMAT_PAGE, $query), $class);
    }

    /**
     * Every item, fetching pages as it goes. Stop iterating whenever you've had enough; no
     * further pages are fetched.
     *
     * @template T of Resource
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $options
     * @param  class-string<T>|null  $class
     * @return ($class is null ? Generator<int, array<string, mixed>> : Generator<int, T>)
     */
    public static function all(Transporter $transporter, string $path, array $query, array $options, ?string $class): Generator
    {
        $payload = Payload::fromPath('GET', $path, null, $options, Payload::FORMAT_PAGE, $query);
        // Following a cursor already followed, as in A → B → A, would loop forever. A cursor
        // the caller started from counts as followed.
        $followed = [];
        $start = $payload->queryParam('cursor');
        if (is_string($start) && $start !== '') {
            $followed[$start] = true;
        }
        while (true) {
            $page = self::fetch($transporter, $payload, $class);
            foreach ($page->data as $item) {
                yield $item;
            }
            // Stop on a null cursor, not an empty page.
            $cursor = $page->nextCursor();
            if ($cursor === null) {
                return;
            }
            if (isset($followed[$cursor])) {
                $info = $page->lastResponse();
                throw new UnserializableResponse("GET {$payload->path}", $info === null ? 200 : $info->status, $info?->requestId, "with cursor $cursor again, so the list would never end");
            }
            $followed[$cursor] = true;
            $payload = $payload->withQuery('cursor', $cursor);
        }
    }

    /**
     * @param  class-string<Resource>|null  $class
     * @return Page<mixed>
     */
    private static function fetch(Transporter $transporter, Payload $payload, ?string $class): Page
    {
        $response = $transporter->request($payload);
        /** @var array{data: list<array<string, mixed>>, meta: array<string, mixed>} $body */
        $body = $response->data;
        $data = $class === null ? $body['data'] : array_map(fn (array $item) => $class::from($item), $body['data']);

        return new Page($data, $body['meta'], $response->info);
    }
}
