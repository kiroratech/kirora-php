<?php

declare(strict_types=1);

namespace Kirora\ValueObjects\Transporter;

/**
 * One call to the API, before it's sent.
 *
 * @internal
 */
final class Payload
{
    /** Any JSON, or no body, decoded whole. */
    public const FORMAT_JSON = 'json';

    /** `{"data": ...}` with an object or array, which the call returns. */
    public const FORMAT_DATA = 'data';

    /** A page of `data` and `meta.pagination`. */
    public const FORMAT_PAGE = 'page';

    /** Any successful response, returned with its body unread. */
    public const FORMAT_RAW = 'raw';

    /**
     * @param  string  $path  Starts with `/v1`, with each id already encoded.
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $options  The call's options, such as `idempotencyKey`.
     * @param  string  $rawQuery  A query string the caller wrote into the path, without its
     *                            `?`, sent as written before `$query`.
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly mixed $body = null,
        public readonly array $options = [],
        public readonly string $format = self::FORMAT_DATA,
        public readonly string $rawQuery = '',
    ) {
    }

    /**
     * A call to a path that may end in a query string, as `Client::request()` takes it. The
     * query string is kept as written, so repeated, dotted and bracketed names reach the API
     * unchanged.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $options
     */
    public static function fromPath(string $method, string $path, mixed $body, array $options, string $format, array $query = []): self
    {
        $rawQuery = '';
        if (str_contains($path, '?')) {
            [$path, $rawQuery] = explode('?', $path, 2);
        }

        return new self(strtoupper($method), $path, $query, $body, $options, $format, $rawQuery);
    }

    /**
     * The first value of a query parameter, from `$query` or the query string, or null.
     */
    public function queryParam(string $name): mixed
    {
        if (array_key_exists($name, $this->query)) {
            return $this->query[$name];
        }
        foreach (self::rawPairs($this->rawQuery) as [$key, $value]) {
            if ($key === $name) {
                return $value;
            }
        }

        return null;
    }

    /**
     * A copy with the query's `$name` set to `$value`, replacing any in the query string.
     */
    public function withQuery(string $name, string $value): self
    {
        $kept = [];
        foreach (explode('&', $this->rawQuery) as $pair) {
            if ($pair !== '' && self::rawPairs($pair)[0][0] !== $name) {
                $kept[] = $pair;
            }
        }

        return new self(
            $this->method,
            $this->path,
            [...$this->query, $name => $value],
            $this->body,
            $this->options,
            $this->format,
            implode('&', $kept),
        );
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private static function rawPairs(string $rawQuery): array
    {
        $pairs = [];
        foreach (explode('&', $rawQuery) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $pairs[] = [urldecode($key), urldecode($value)];
        }

        return $pairs;
    }
}
