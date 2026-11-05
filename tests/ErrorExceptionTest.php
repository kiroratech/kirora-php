<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use Kirora\Enums\ErrorCode;
use Kirora\Exceptions\ErrorException;
use Kirora\Exceptions\KiroraException;
use Kirora\ResponseInfo;

function errorFrom(int $status, ?string $body, array $headers = []): ErrorException
{
    return ErrorException::fromResponse(ResponseInfo::fromResponse(new Response($status, $headers)), $body);
}

it('reads the API\'s error', function () {
    $error = errorFrom(422, json_encode(['error' => [
        'code' => 'VALIDATION_ERROR',
        'message' => 'The request is invalid',
        'errors' => [
            ['field' => 'email', 'message' => 'Not an email', 'code' => 'invalid'],
            ['field' => 'phone'],
            'nonsense',
        ],
        'details' => ['limit' => 100],
    ]]), ['X-Request-Id' => 'req_9']);

    expect($error)->toBeInstanceOf(KiroraException::class)
        ->and($error->errorCode)->toBe('VALIDATION_ERROR')
        ->and($error->is(ErrorCode::ValidationError))->toBeTrue()
        ->and($error->is('VALIDATION_ERROR'))->toBeTrue()
        ->and($error->isKnownCode())->toBeTrue()
        ->and($error->status)->toBe(422)
        ->and($error->getCode())->toBe(422)
        ->and($error->errors)->toBe([['field' => 'email', 'message' => 'Not an email', 'code' => 'invalid']])
        ->and($error->details)->toBe(['limit' => 100])
        ->and($error->requestId)->toBe('req_9')
        ->and($error->isRetryable())->toBeFalse()
        ->and($error->getMessage())->toBe('Kirora: VALIDATION_ERROR (HTTP 422): The request is invalid [request req_9]');
});

it('gets a code from the status when the body has none', function (int $status, ErrorCode $code) {
    $error = errorFrom($status, '<html>Bad gateway</html>');
    expect($error->errorCode)->toBe($code->value)
        ->and($error->errorMessage)->toBe("HTTP $status");
})->with([
    [400, ErrorCode::BadRequest],
    [401, ErrorCode::Unauthorized],
    [403, ErrorCode::Forbidden],
    [404, ErrorCode::NotFound],
    [409, ErrorCode::Conflict],
    [418, ErrorCode::BadRequest],
    [429, ErrorCode::RateLimited],
    [502, ErrorCode::InternalError],
    [503, ErrorCode::ServiceUnavailable],
]);

it('keeps a code newer than the SDK', function () {
    $error = errorFrom(400, json_encode(['error' => ['code' => 'BRAND_NEW', 'message' => 'x']]));
    expect($error->errorCode)->toBe('BRAND_NEW')->and($error->isKnownCode())->toBeFalse();
});

it('takes the request id from the body when the header is missing', function () {
    $error = errorFrom(500, json_encode(['error' => ['requestId' => 'req_body']]));
    expect($error->requestId)->toBe('req_body')->and($error->isRetryable())->toBeTrue();
});

it('reads Retry-After as seconds or a date', function () {
    expect(errorFrom(429, null, ['Retry-After' => '1.5'])->retryAfter)->toBe(1.5)
        ->and(errorFrom(429, null, ['Retry-After' => 'Wed, 21 Oct 2015 07:28:00 GMT'])->retryAfter)->toBe(0.0)
        ->and(errorFrom(429, null, ['Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', time() + 120)])->retryAfter)
        ->toBeGreaterThanOrEqual(118.0)->toBeLessThanOrEqual(120.0)
        ->and(errorFrom(429, null, ['Retry-After' => '1e3'])->retryAfter)->toBeNull()
        ->and(errorFrom(429, null)->retryAfter)->toBeNull();
});

it('is retryable after a rate limit or a server error', function () {
    expect(errorFrom(429, null)->isRetryable())->toBeTrue()
        ->and(errorFrom(500, null)->isRetryable())->toBeTrue()
        ->and(errorFrom(409, json_encode(['error' => ['code' => 'IDEMPOTENCY_KEY_IN_USE']]))->isRetryable())->toBeTrue()
        ->and(errorFrom(409, null)->isRetryable())->toBeFalse();
});
