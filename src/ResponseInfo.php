<?php

declare(strict_types=1);

namespace Kirora;

use Psr\Http\Message\ResponseInterface;

/**
 * The HTTP response a result came from, as its `lastResponse()`.
 */
final class ResponseInfo
{
    /**
     * @param  array<string, list<string>>  $headers  By lowercase name.
     * @param  ?string  $requestId  The `X-Request-Id` header. Quote it to Kirora support.
     * @param  bool  $idempotencyReplay  True when the API answered with its stored response to
     *                                   an earlier request with the same idempotency key, rather than acting again.
     * @param  ?RateLimit  $rateLimit  Null unless the response had valid `RateLimit-*` headers.
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly ?string $requestId,
        public readonly bool $idempotencyReplay,
        public readonly ?RateLimit $rateLimit,
    ) {
    }

    /**
     * @internal
     */
    public static function fromResponse(ResponseInterface $response): self
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[strtolower((string) $name)] = array_values(array_map('strval', $values));
        }
        $requestId = $headers['x-request-id'][0] ?? '';

        return new self(
            status: $response->getStatusCode(),
            headers: $headers,
            requestId: $requestId === '' ? null : $requestId,
            idempotencyReplay: ($headers['idempotency-replay'][0] ?? '') === 'true',
            rateLimit: RateLimit::fromHeaders($headers),
        );
    }

    /**
     * The header's first value, or null if the response doesn't have it.
     */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }
}
