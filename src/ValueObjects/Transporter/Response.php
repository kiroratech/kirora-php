<?php

declare(strict_types=1);

namespace Kirora\ValueObjects\Transporter;

use Kirora\ResponseInfo;

/**
 * What a call returned: its decoded body, or for a raw call the PSR-7 response, and the
 * response it came from.
 *
 * @internal
 */
final class Response
{
    public function __construct(
        public readonly mixed $data,
        public readonly ResponseInfo $info,
    ) {
    }
}
