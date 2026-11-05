<?php

declare(strict_types=1);

namespace Kirora\Exceptions;

use Throwable;

/**
 * Every exception the SDK throws for a failed call implements this, so one `catch` can
 * handle them all. Invalid arguments, such as an empty id, throw PHP's
 * `InvalidArgumentException` instead, before anything is sent.
 */
interface KiroraException extends Throwable
{
}
