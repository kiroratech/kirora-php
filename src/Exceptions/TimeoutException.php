<?php

declare(strict_types=1);

namespace Kirora\Exceptions;

/**
 * An attempt took longer than its `timeout`. The request may still have reached the API.
 */
class TimeoutException extends TransporterException
{
}
