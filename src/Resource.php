<?php

declare(strict_types=1);

namespace Kirora;

use BadMethodCallException;
use Kirora\Contracts\Resource as ResourceContract;

/**
 * An object the API returned. Read its fields as properties, `$contact->email`, or as array
 * keys, `$contact['email']`; a field the response doesn't have reads as null. Fields this
 * version of the SDK doesn't know are kept, so nothing the API sends is lost.
 *
 * The generated classes in `Kirora\Models` extend it, documenting each field, and turn
 * nested objects into their own models.
 */
class Resource implements ResourceContract
{
    /**
     * How to turn each field's JSON into models: a model class, `['list' => cast]` for an array
     * of them, or `['map' => cast]` for an object of them by key.
     *
     * @var array<string, mixed>
     */
    protected const CASTS = [];

    /** @var array<string, mixed> */
    private array $attributes = [];

    private ?ResponseInfo $lastResponse = null;

    /**
     * @param  array<array-key, mixed>  $attributes
     */
    final public function __construct(array $attributes = [])
    {
        foreach ($attributes as $name => $value) {
            $this->attributes[(string) $name] = self::cast(static::CASTS[$name] ?? null, $value);
        }
    }

    /**
     * @param  array<array-key, mixed>  $attributes
     */
    public static function from(array $attributes, ?ResponseInfo $response = null): static
    {
        $resource = new static($attributes);
        $resource->lastResponse = $response;

        return $resource;
    }

    /**
     * Whether every item of `$items` is a JSON object, as decoded: an array with string keys,
     * or empty.
     *
     * @param  array<mixed>  $items
     *
     * @internal
     */
    public static function areObjects(array $items): bool
    {
        foreach ($items as $item) {
            if (! is_array($item) || ($item !== [] && array_is_list($item))) {
                return false;
            }
        }

        return true;
    }

    /**
     * The HTTP response this came from, with its request id and rate limit. Null for an
     * object nested in another.
     */
    public function lastResponse(): ?ResponseInfo
    {
        return $this->lastResponse;
    }

    private static function cast(mixed $cast, mixed $value): mixed
    {
        if ($cast === null || ! is_array($value)) {
            return $value;
        }
        if (is_string($cast)) {
            /** @var class-string<Resource> $cast */
            return $cast::from($value);
        }
        if (is_array($cast) && isset($cast['list']) && array_is_list($value)) {
            return array_map(fn ($item) => self::cast($cast['list'], $item), $value);
        }
        if (is_array($cast) && isset($cast['map'])) {
            return array_map(fn ($item) => self::cast($cast['map'], $item), $value);
        }

        return $value;
    }

    /**
     * A field, or null if the response doesn't have it.
     */
    public function getAttribute(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    /**
     * Every field, with nested objects as models.
     *
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function toArray(): array
    {
        return self::plain($this->attributes);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private static function plain(array $values): array
    {
        return array_map(
            fn ($value) => match (true) {
                $value instanceof Resource => $value->toArray(),
                is_array($value) => self::plain($value),
                default => $value,
            },
            $values,
        );
    }

    public function toJson(int $flags = 0): string
    {
        return (string) json_encode($this->jsonSerialize(), $flags | JSON_THROW_ON_ERROR);
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    public function __get(string $name): mixed
    {
        return $this->getAttribute($name);
    }

    public function __isset(string $name): bool
    {
        return isset($this->attributes[$name]);
    }

    public function __set(string $name, mixed $value): void
    {
        throw new BadMethodCallException('Kirora: resources are read-only');
    }

    public function __unset(string $name): void
    {
        throw new BadMethodCallException('Kirora: resources are read-only');
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return $this->attributes;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->attributes[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->attributes[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new BadMethodCallException('Kirora: resources are read-only');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new BadMethodCallException('Kirora: resources are read-only');
    }
}
