<?php

declare(strict_types=1);

namespace Kirora\Exceptions;

use RuntimeException;
use Throwable;

/**
 * No complete response arrived, such as after a network error, a timeout, or the
 * connection dropping while the body was read. The cause is `getPrevious()`.
 */
class TransporterException extends RuntimeException implements KiroraException
{
    /**
     * @param  string  $operation  The request's method and path, such as `GET /v1/contacts`.
     * @param  bool  $neverConnected  Whether the connection was never made, so the API
     *                                certainly didn't act on the request.
     *
     * @internal
     */
    public function __construct(
        public readonly string $operation,
        string $message,
        ?Throwable $previous = null,
        public readonly bool $neverConnected = false,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
