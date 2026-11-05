<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\ServerRequest;
use Kirora\Enums\WebhookEvent;
use Kirora\Enums\WebhookVerificationFailure;
use Kirora\Exceptions\WebhookVerificationException;
use Kirora\Webhook;

const SECRET = 'whsec_test';
const NOW = 1_800_000_000;

function sign(string $body, int|string $timestamp = NOW, string $secret = SECRET): string
{
    return 'sha256=' . hash_hmac('sha256', "$timestamp.$body", $secret);
}

/**
 * @return array{0: string, 1: array<string, string>}
 */
function delivery(array $overrides = [], ?string $body = null): array
{
    $body ??= json_encode(['eventId' => 'evt_1', 'eventType' => 'message.delivered', 'messageId' => 'm1']);

    return [$body, [
        'X-Webhook-Event' => 'message.delivered',
        'X-Webhook-Event-Id' => 'evt_1',
        'X-Webhook-Timestamp' => (string) NOW,
        'X-Webhook-Signature' => sign($body),
        'X-Webhook-Attempt' => '2',
        ...$overrides,
    ]];
}

function reason(Closure $verify): WebhookVerificationFailure
{
    try {
        $verify();
    } catch (WebhookVerificationException $error) {
        return $error->reason;
    }
    throw new RuntimeException('expected verification to fail');
}

it('verifies a webhook', function () {
    [$body, $headers] = delivery();
    $webhook = Webhook::verify(SECRET, $body, $headers, ['now' => NOW]);

    expect($webhook)->not->toBeNull()
        ->and($webhook->headers->event)->toBe(WebhookEvent::MessageDelivered->value)
        ->and($webhook->headers->eventId)->toBe('evt_1')
        ->and($webhook->headers->attempt)->toBe(2)
        ->and($webhook->payload['messageId'])->toBe('m1');
});

it('reads headers from $_SERVER, lowercase arrays and PSR-7 requests', function () {
    [$body, $headers] = delivery();
    $server = [];
    foreach ($headers as $name => $value) {
        $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
    }
    $lowercase = array_map(fn ($v) => [$v], array_change_key_case($headers));
    $request = new ServerRequest('POST', '/webhooks/kirora', $headers, $body);

    foreach ([$server, $lowercase, $request] as $given) {
        expect(Webhook::verify(SECRET, $body, $given, ['now' => NOW]))->not->toBeNull();
    }
});

it('accepts any one of several signatures', function () {
    [$body, $headers] = delivery();
    $headers['X-Webhook-Signature'] = 'sha256=' . str_repeat('0', 64) . ', ' . $headers['X-Webhook-Signature'];
    expect(Webhook::verify(SECRET, $body, $headers, ['now' => NOW]))->not->toBeNull();
});

it('rejects a body changed after signing', function () {
    [$body, $headers] = delivery();
    expect(Webhook::verify(SECRET, $body . ' ', $headers, ['now' => NOW]))->toBeNull()
        ->and(reason(fn () => Webhook::assert(SECRET, $body . ' ', $headers, ['now' => NOW])))
        ->toBe(WebhookVerificationFailure::SignatureMismatch);
});

it('rejects the wrong secret', function () {
    [$body, $headers] = delivery();
    expect(reason(fn () => Webhook::assert('other', $body, $headers, ['now' => NOW])))
        ->toBe(WebhookVerificationFailure::SignatureMismatch);
});

it('rejects a timestamp outside the tolerance, either way', function () {
    [$body, $headers] = delivery();
    expect(reason(fn () => Webhook::assert(SECRET, $body, $headers, ['now' => NOW + 301])))
        ->toBe(WebhookVerificationFailure::TimestampOutsideTolerance)
        ->and(reason(fn () => Webhook::assert(SECRET, $body, $headers, ['now' => NOW - 301])))
        ->toBe(WebhookVerificationFailure::TimestampOutsideTolerance)
        ->and(Webhook::verify(SECRET, $body, $headers, ['now' => NOW + 300]))->not->toBeNull()
        ->and(Webhook::verify(SECRET, $body, $headers, ['now' => new DateTimeImmutable('@' . (NOW + 60)), 'tolerance' => 60]))->not->toBeNull();
});

it('rejects missing or malformed headers', function (array $overrides, WebhookVerificationFailure $failure) {
    [$body, $headers] = delivery();
    $headers = array_filter([...$headers, ...$overrides], fn ($v) => $v !== null);
    expect(reason(fn () => Webhook::assert(SECRET, $body, $headers, ['now' => NOW])))->toBe($failure)
        ->and(Webhook::readHeaders($headers))->toBeNull();
})->with([
    [['X-Webhook-Timestamp' => null], WebhookVerificationFailure::InvalidTimestamp],
    [['X-Webhook-Signature' => null], WebhookVerificationFailure::InvalidHeaders],
    [['X-Webhook-Event' => null], WebhookVerificationFailure::InvalidHeaders],
    [['X-Webhook-Event-Id' => null], WebhookVerificationFailure::InvalidHeaders],
    [['X-Webhook-Attempt' => '0'], WebhookVerificationFailure::InvalidHeaders],
    [['X-Webhook-Attempt' => '01'], WebhookVerificationFailure::InvalidHeaders],
]);

it('rejects a timestamp that isn\'t whole seconds', function (string $timestamp) {
    expect(reason(fn () => Webhook::assertSignature(SECRET, '{}', sign('{}', $timestamp), $timestamp)))
        ->toBe(WebhookVerificationFailure::InvalidTimestamp);
})->with(['-1', '1.5', '+1', ' 1', '1e9']);

it('rejects event headers that don\'t match the signed body', function () {
    [$body, $headers] = delivery(['X-Webhook-Event-Id' => 'evt_other']);
    expect(reason(fn () => Webhook::assert(SECRET, $body, $headers, ['now' => NOW])))
        ->toBe(WebhookVerificationFailure::InvalidHeaders);
});

it('rejects a body over 10 MiB', function () {
    [, $headers] = delivery();
    expect(reason(fn () => Webhook::assert(SECRET, str_repeat(' ', (10 << 20) + 1), $headers)))
        ->toBe(WebhookVerificationFailure::BodyTooLarge);
});

it('checks a signature on its own', function () {
    expect(Webhook::verifySignature(SECRET, '{}', sign('{}'), (string) NOW, ['now' => NOW]))->toBeTrue()
        ->and(Webhook::verifySignature(SECRET, '{}', sign('{"a":1}'), (string) NOW, ['now' => NOW]))->toBeFalse();
});

it('throws for an empty secret or invalid options, even from verify()', function (string $secret, array $options) {
    [$body, $headers] = delivery();
    Webhook::verify($secret, $body, $headers, $options);
})->with([
    ['', []],
    [SECRET, ['tolerance' => -1]],
    [SECRET, ['now' => 'yesterday']],
    [SECRET, ['toleranceSeconds' => 5]],
])->throws(InvalidArgumentException::class);

it('rejects headers with a trailing newline', function (string $name, string $value, WebhookVerificationFailure $failure) {
    [$body, $headers] = delivery([$name => $value]);
    expect(reason(fn () => Webhook::assert(SECRET, $body, $headers, ['now' => NOW])))->toBe($failure);
})->with([
    ['X-Webhook-Attempt', "2\n", WebhookVerificationFailure::InvalidHeaders],
    ['X-Webhook-Timestamp', NOW . "\n", WebhookVerificationFailure::InvalidTimestamp],
]);
