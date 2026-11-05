<?php

declare(strict_types=1);

namespace Kirora\Transporters;

/**
 * A request built once, so that every attempt sends the same body and idempotency key.
 *
 * @internal
 */
final class PreparedRequest
{
    /**
     * @param  array<string, string>  $headers
     * @param  bool  $notFoundIsDone  Makes a 404 after an attempt that may have reached the API a
     *                                success, since that attempt may have done the delete.
     */
    public function __construct(
        public readonly string $operation,
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers,
        public readonly ?string $body,
        public readonly string $format,
        public readonly bool $safeToRetry,
        public readonly bool $notFoundIsDone,
    ) {
    }
}
