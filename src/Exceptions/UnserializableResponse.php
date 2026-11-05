<?php

declare(strict_types=1);

namespace Kirora\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The API answered, but the SDK couldn't use the response: a body that isn't the JSON
 * expected, such as HTML from a proxy, a body over 64 MiB, a redirect, or a list that
 * would never end.
 */
class UnserializableResponse extends RuntimeException implements KiroraException
{
    /**
     * @internal
     */
    public function __construct(
        public readonly string $operation,
        public readonly int $status,
        public readonly ?string $requestId,
        string $problem,
        ?Throwable $previous = null,
    ) {
        $message = "Kirora: $operation returned $status $problem";
        if ($requestId !== null && $requestId !== '') {
            $message .= " [request $requestId]";
        }
        parent::__construct($message, $status, $previous);
    }
}
