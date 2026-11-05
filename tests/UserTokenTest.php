<?php

declare(strict_types=1);

use Kirora\UserToken;

function decodePart(string $part): array
{
    return json_decode(base64_decode(strtr($part, '-_', '+/')), true);
}

it('signs a token for the user', function () {
    $before = time();
    $token = UserToken::create('sdk_secret', 'user-123');
    [$header, $claims, $signature] = explode('.', $token);

    expect(decodePart($header))->toBe(['alg' => 'HS256', 'typ' => 'JWT'])
        ->and(decodePart($claims)['sub'])->toBe('user-123')
        ->and(decodePart($claims)['iat'])->toBeGreaterThanOrEqual($before)
        ->and(decodePart($claims)['exp'] - decodePart($claims)['iat'])->toBe(3600)
        ->and($signature)->toBe(rtrim(strtr(base64_encode(hash_hmac('sha256', "$header.$claims", 'sdk_secret', true)), '+/', '-_'), '='))
        ->and($token)->not->toContain('=');
});

it('takes a lifetime from 1 second to 24 hours', function () {
    $claims = decodePart(explode('.', UserToken::create('s', 'u', 86400))[1]);
    expect($claims['exp'] - $claims['iat'])->toBe(86400);
});

it('refuses to sign without a secret, a user or a valid lifetime', function (string $secret, string $user, int $lifetime) {
    UserToken::create($secret, $user, $lifetime);
})->with([
    ['', 'u', 3600],
    ['s', '', 3600],
    ['s', 'u', 0],
    ['s', 'u', 86401],
])->throws(InvalidArgumentException::class);
