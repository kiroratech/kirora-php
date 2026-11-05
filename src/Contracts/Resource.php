<?php

declare(strict_types=1);

namespace Kirora\Contracts;

use ArrayAccess;
use JsonSerializable;

/**
 * An object the API returned, read as properties or array keys.
 *
 * @extends ArrayAccess<string, mixed>
 */
interface Resource extends ArrayAccess, JsonSerializable
{
    /**
     * The object as the API returned it, with nested objects as arrays.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
