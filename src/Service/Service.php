<?php

declare(strict_types=1);

namespace Kirora\Service;

use Generator;
use Kirora\Contracts\Transporter;
use Kirora\Exceptions\UnserializableResponse;
use Kirora\Page;
use Kirora\Paginator;
use Kirora\Resource;
use Kirora\ValueObjects\Transporter\Payload;

/**
 * The API's operations on one resource.
 *
 * Every method takes the call's options as its last argument, overriding the client's for
 * that call: `idempotencyKey`, `workspace`, `headers`, `timeout`, `maxRetries` and
 * `maxRetryDelay`.
 */
abstract class Service
{
    /**
     * @internal
     */
    public function __construct(
        protected readonly Transporter $transporter,
    ) {
    }

    /**
     * Formats a path, encoding each id as a single path segment.
     */
    protected static function path(string $format, string ...$ids): string
    {
        return sprintf($format, ...array_map('rawurlencode', $ids));
    }

    /**
     * Sends a call whose response is a `data` envelope, and returns what's in it as a `$class`.
     *
     * @template T of Resource
     *
     * @param  class-string<T>  $class
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $options
     * @return T
     */
    protected function one(string $class, string $method, string $path, array $query = [], mixed $body = null, array $options = []): Resource
    {
        $response = $this->transporter->request(new Payload($method, $path, $query, $body, $options));

        /** @var array<string, mixed> $data */
        $data = $response->data;

        return $class::from($data, $response->info);
    }

    /**
     * Sends a call whose response is a `data` array, and returns each item as a `$class`.
     *
     * @template T of Resource
     *
     * @param  class-string<T>  $class
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $options
     * @return list<T>
     */
    protected function many(string $class, string $method, string $path, array $query = [], array $options = []): array
    {
        $response = $this->transporter->request(new Payload($method, $path, $query, null, $options));
        $data = $response->data;
        if (! is_array($data) || ! array_is_list($data) || ! Resource::areObjects($data)) {
            throw new UnserializableResponse("$method $path", $response->info->status, $response->info->requestId, 'without an array of objects in its `data` envelope');
        }

        /** @var list<array<string, mixed>> $data */
        return array_map(fn (array $item) => $class::from($item, $response->info), $data);
    }

    /**
     * Sends a call that has no response body, such as a delete.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $options
     */
    protected function none(string $method, string $path, array $query = [], array $options = []): void
    {
        $this->transporter->request(new Payload($method, $path, $query, null, $options, Payload::FORMAT_JSON));
    }

    /**
     * @template T of Resource
     *
     * @param  class-string<T>  $class
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $options
     * @return Page<T>
     */
    protected function page(string $class, string $path, array $query, array $options): Page
    {
        return Paginator::page($this->transporter, $path, $query, $options, $class);
    }

    /**
     * @template T of Resource
     *
     * @param  class-string<T>  $class
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $options
     * @return Generator<int, T>
     */
    protected function every(string $class, string $path, array $query, array $options): Generator
    {
        return Paginator::all($this->transporter, $path, $query, $options, $class);
    }
}
