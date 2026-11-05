<?php

declare(strict_types=1);

namespace Kirora;

use InvalidArgumentException;

/**
 * Tokens that sign a user in to Kirora's web and mobile SDKs.
 */
final class UserToken
{
    /** How long a token lasts unless you say otherwise: an hour. */
    public const DEFAULT_LIFETIME = 3600;

    private const MAX_LIFETIME = 86400;

    /**
     * Creates a token for the signed-in user. Serve it from a route on your server that only
     * that user can call.
     *
     * @param  string  $tokenSecret  The SDK token secret from Developer → SDK in the dashboard.
     *                               Never expose it to the browser.
     * @param  string  $userId  Your own id for the user: their contact's externalId.
     * @param  int  $expiresInSeconds  How long the token lasts, from 1 to 86,400 (24 hours).
     *
     * @throws InvalidArgumentException for an empty secret or user id, or another lifetime,
     *                                  rather than signing a token.
     */
    public static function create(string $tokenSecret, string $userId, int $expiresInSeconds = self::DEFAULT_LIFETIME): string
    {
        if ($tokenSecret === '') {
            throw new InvalidArgumentException('Kirora: a token secret is required');
        }
        if ($userId === '') {
            throw new InvalidArgumentException('Kirora: a user id is required');
        }
        if ($expiresInSeconds < 1 || $expiresInSeconds > self::MAX_LIFETIME) {
            throw new InvalidArgumentException('Kirora: a user token must last from 1 to ' . self::MAX_LIFETIME . " seconds, not $expiresInSeconds");
        }
        $issuedAt = time();
        $encode = fn (array $value) => self::base64Url(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $signingInput = $encode(['alg' => 'HS256', 'typ' => 'JWT'])
            . '.' . $encode(['sub' => $userId, 'iat' => $issuedAt, 'exp' => $issuedAt + $expiresInSeconds]);

        return $signingInput . '.' . self::base64Url(hash_hmac('sha256', $signingInput, $tokenSecret, true));
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
