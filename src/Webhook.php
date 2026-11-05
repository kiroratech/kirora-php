<?php

declare(strict_types=1);

namespace Kirora;

use DateTimeInterface;
use InvalidArgumentException;
use Kirora\Enums\WebhookVerificationFailure as Failure;
use Kirora\Exceptions\WebhookVerificationException;
use Psr\Http\Message\MessageInterface;

/**
 * Verifies that webhooks came from Kirora.
 *
 * Pass the raw request body, before any JSON decoding: decoded and re-encoded JSON won't
 * match the signature. In plain PHP, that's `file_get_contents('php://input')`, with
 * `getallheaders()` or `$_SERVER` for the headers; a PSR-7 request can be passed as the
 * headers.
 *
 * Webhooks can arrive more than once, so deduplicate on the payload's `eventId`, recording an
 * event as handled only once its work has succeeded.
 */
final class Webhook
{
    /**
     * How far a webhook's timestamp may be from now, in seconds, before it's rejected as a
     * replay: 5 minutes.
     */
    public const DEFAULT_TOLERANCE = 300;

    private const MAX_BODY = 10 << 20;

    /**
     * Checks a webhook as `assert()` does, and returns its headers and decoded body, or null
     * if it fails. An empty secret or an invalid option still throws.
     *
     * @param  array<string, mixed>|MessageInterface  $headers
     * @param  array{tolerance?: int|float, now?: int|float|DateTimeInterface}  $options
     */
    public static function verify(string $secret, string $rawBody, array|MessageInterface $headers, array $options = []): ?VerifiedWebhook
    {
        try {
            return self::assert($secret, $rawBody, $headers, $options);
        } catch (WebhookVerificationException) {
            return null;
        }
    }

    /**
     * Checks that a webhook came from Kirora, as `assertHeaders()` and `assertSignature()` do,
     * and that its event headers match the signed body. Returns the headers and the decoded
     * body.
     *
     * @param  array<string, mixed>|MessageInterface  $headers
     * @param  array{tolerance?: int|float, now?: int|float|DateTimeInterface}  $options
     *
     * @throws WebhookVerificationException saying why a webhook fails.
     * @throws InvalidArgumentException for an empty secret or an invalid option.
     */
    public static function assert(string $secret, string $rawBody, array|MessageInterface $headers, array $options = []): VerifiedWebhook
    {
        $read = self::assertHeaders($headers);
        if (strlen($rawBody) > self::MAX_BODY) {
            throw new WebhookVerificationException(Failure::BodyTooLarge, 'the body is larger than 10 MiB');
        }
        self::assertSignature($secret, $rawBody, $read->signature, $read->timestamp, $options);
        $payload = json_decode($rawBody, true);
        // The signature covers only the timestamp and body, so the headers could have been
        // changed on a replay.
        if (
            ! is_array($payload) || array_is_list($payload)
            || ($payload['eventId'] ?? null) !== $read->eventId
            || ($payload['eventType'] ?? null) !== $read->event
        ) {
            throw new WebhookVerificationException(Failure::InvalidHeaders, 'the event or event id header doesn\'t match the signed body');
        }

        /** @var array<string, mixed> $payload */
        return new VerifiedWebhook($read, $payload);
    }

    /**
     * Returns whether `$signature` signs the raw body and timestamp with `$secret`, and the
     * timestamp is within the tolerance, checked as `assertSignature()` checks them.
     *
     * @param  array{tolerance?: int|float, now?: int|float|DateTimeInterface}  $options
     */
    public static function verifySignature(string $secret, string $rawBody, string $signature, string $timestamp, array $options = []): bool
    {
        try {
            self::assertSignature($secret, $rawBody, $signature, $timestamp, $options);

            return true;
        } catch (WebhookVerificationException) {
            return false;
        }
    }

    /**
     * Checks that `$signature`, as sent in a webhook's headers, signs the raw body and
     * timestamp with `$secret`, and that the timestamp is within the tolerance. The signature
     * may list several, separated by commas; one match is enough.
     *
     * Options: `tolerance`, how far the timestamp may be from now in seconds, either way
     * (default `DEFAULT_TOLERANCE`), and `now`, the time to check against, as Unix seconds or
     * a `DateTimeInterface` (default the current time).
     *
     * @param  array{tolerance?: int|float, now?: int|float|DateTimeInterface}  $options
     *
     * @throws WebhookVerificationException saying why a webhook fails.
     * @throws InvalidArgumentException for an empty secret or an invalid option.
     */
    public static function assertSignature(string $secret, string $rawBody, string $signature, string $timestamp, array $options = []): void
    {
        if ($secret === '') {
            throw new InvalidArgumentException('Kirora: a webhook secret is required');
        }
        $unknown = array_diff(array_keys($options), ['tolerance', 'now']);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Kirora: webhook verification takes the options tolerance and now, not ' . implode(', ', $unknown));
        }
        $tolerance = $options['tolerance'] ?? self::DEFAULT_TOLERANCE;
        if (! is_int($tolerance) && ! is_float($tolerance) || ! is_finite((float) $tolerance) || $tolerance < 0) {
            throw new InvalidArgumentException('Kirora: tolerance must be a number of seconds of at least 0');
        }
        $now = $options['now'] ?? time();
        if ($now instanceof DateTimeInterface) {
            $now = $now->getTimestamp();
        }
        if (! is_int($now) && ! is_float($now) || ! is_finite((float) $now)) {
            throw new InvalidArgumentException('Kirora: now must be Unix seconds or a DateTimeInterface');
        }

        if (! preg_match('/^\d{1,15}\z/', $timestamp)) {
            throw new WebhookVerificationException(Failure::InvalidTimestamp, 'the timestamp header is missing or not a number of seconds');
        }
        $expected = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
        $matched = false;
        // Every candidate is compared, so the time taken doesn't reveal which one matched.
        foreach (explode(',', $signature) as $candidate) {
            if (hash_equals($expected, trim($candidate))) {
                $matched = true;
            }
        }
        if (! $matched) {
            throw new WebhookVerificationException(Failure::SignatureMismatch, 'no signature matches the body: check the secret, and pass the raw body undecoded');
        }
        // Checked after the signature, so a webhook rejected as too old or too new is one
        // Kirora did sign.
        $age = (int) floor($now) - (int) $timestamp;
        if (abs($age) > $tolerance) {
            throw new WebhookVerificationException(Failure::TimestampOutsideTolerance, sprintf(
                'the timestamp is %ds %s, beyond the %ss tolerance: a replay, or a clock out of sync',
                abs($age),
                $age > 0 ? 'old' : 'in the future',
                $tolerance,
            ));
        }
    }

    /**
     * Reads Kirora's headers from a webhook request, as `assertHeaders()` does, but returns
     * null if one is missing or malformed. The signature doesn't cover `event` and `eventId`:
     * use `verify()`, or take `eventType` and `eventId` from the verified body.
     *
     * @param  array<string, mixed>|MessageInterface  $headers
     */
    public static function readHeaders(array|MessageInterface $headers): ?WebhookHeaders
    {
        try {
            return self::assertHeaders($headers);
        } catch (WebhookVerificationException) {
            return null;
        }
    }

    /**
     * Reads Kirora's headers from a webhook request: a PSR-7 request, `getallheaders()`, or
     * `$_SERVER`. Verify the request with `assertSignature()` before trusting them.
     *
     * @param  array<string, mixed>|MessageInterface  $headers
     *
     * @throws WebhookVerificationException if one is missing or malformed.
     */
    public static function assertHeaders(array|MessageInterface $headers): WebhookHeaders
    {
        $get = self::headerReader($headers);
        $event = $get('x-webhook-event');
        $eventId = $get('x-webhook-event-id');
        $timestamp = $get('x-webhook-timestamp');
        $signature = $get('x-webhook-signature');
        $attempt = $get('x-webhook-attempt') ?? '1';
        if ($timestamp === null || $timestamp === '') {
            throw new WebhookVerificationException(Failure::InvalidTimestamp, 'the timestamp header is missing');
        }
        if ($signature === null || $signature === '') {
            throw new WebhookVerificationException(Failure::InvalidHeaders, 'the signature header is missing');
        }
        if ($event === null || $event === '' || $eventId === null || $eventId === '') {
            throw new WebhookVerificationException(Failure::InvalidHeaders, 'the event or event id header is missing');
        }
        if (! preg_match('/^[1-9]\d{0,15}\z/', $attempt)) {
            throw new WebhookVerificationException(Failure::InvalidHeaders, 'the attempt header isn\'t a whole number from 1');
        }

        return new WebhookHeaders($event, $eventId, (int) $attempt, $timestamp, $signature);
    }

    /**
     * @param  array<string, mixed>|MessageInterface  $headers
     * @return \Closure(string): ?string
     */
    private static function headerReader(array|MessageInterface $headers): \Closure
    {
        if ($headers instanceof MessageInterface) {
            return fn (string $name) => $headers->hasHeader($name) ? $headers->getHeader($name)[0] : null;
        }
        $lookup = [];
        foreach ($headers as $name => $value) {
            $name = strtolower((string) $name);
            // $_SERVER names headers as HTTP_X_WEBHOOK_EVENT.
            if (str_starts_with($name, 'http_')) {
                $name = str_replace('_', '-', substr($name, 5));
            }
            if (is_array($value)) {
                $value = $value === [] ? null : reset($value);
            }
            if (is_string($value)) {
                $lookup[$name] ??= $value;
            }
        }

        return fn (string $name) => $lookup[$name] ?? null;
    }
}
