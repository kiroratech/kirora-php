<?php

declare(strict_types=1);

namespace Kirora\Exceptions;

use Kirora\Enums\WebhookVerificationFailure;
use RuntimeException;

/**
 * A webhook failed verification, so reject the request. `reason` says why.
 */
class WebhookVerificationException extends RuntimeException implements KiroraException
{
    /**
     * @internal
     */
    public function __construct(
        public readonly WebhookVerificationFailure $reason,
        string $message,
    ) {
        parent::__construct("Kirora: $message");
    }
}
