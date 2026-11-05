<?php

declare(strict_types=1);

namespace Kirora;

/**
 * The rate limit the API reported with a response, from its `RateLimit-*` headers.
 */
final class RateLimit
{
    /**
     * @param  int  $limit  How many requests the window allows.
     * @param  int  $remaining  How many requests are left in the current window.
     * @param  int  $resetSeconds  How long until the window resets.
     */
    public function __construct(
        public readonly int $limit,
        public readonly int $remaining,
        public readonly int $resetSeconds,
    ) {
    }

    /**
     * Null unless all three headers are valid.
     *
     * @param  array<string, list<string>>  $headers
     *
     * @internal
     */
    public static function fromHeaders(array $headers): ?self
    {
        $values = [];
        foreach (['ratelimit-limit', 'ratelimit-remaining', 'ratelimit-reset'] as $name) {
            $value = trim($headers[$name][0] ?? '');
            if (! preg_match('/^\d{1,18}\z/', $value)) {
                return null;
            }
            $values[] = (int) $value;
        }

        return new self(...$values);
    }
}
