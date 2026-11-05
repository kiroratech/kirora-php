<?php

declare(strict_types=1);

namespace Kirora;

use DateTimeImmutable;
use DateTimeZone;
use Kirora\Exceptions\ErrorException;
use Kirora\Exceptions\TransporterException;
use Kirora\Exceptions\UnserializableResponse;
use Throwable;

/**
 * How a request may be retried, from the API spec's `x-retry` for its endpoint.
 *
 * - `read` changes nothing.
 * - `converges` leaves the same end state however often it's repeated.
 * - `state` moves a resource to a state, which a repeat can't do twice.
 * - `done` finishes or removes something, which a repeat finds already done.
 * - `entries` is a batch whose entries each carry an idempotency key; see `withEntryKeys()`.
 * - `key` and `unsafe` can do their work twice if repeated, so need a key to be retried.
 * - `unique` creates something that must be unique, so a repeat after the first attempt
 *   succeeded would fail as a conflict.
 *
 * @internal
 */
final class Retry
{
    private const SAFE_WITHOUT_KEY = ['read', 'converges', 'state', 'done', 'entries'];

    /** @var array<string, list<array{segments: list<string>, operation: array<string, mixed>}>>|null */
    private static ?array $templates = null;

    /**
     * The operation whose path template matches the path's segments. Of several matches, the
     * one with the most literal segments wins, as a literal such as "batch" is a closer match
     * than an {id}.
     *
     * @param  list<string>  $segments
     * @return array{id: string, retry: ?string, acceptsKey: bool, entries: ?string, lookupSegment: int, noContent: bool, objects: list<string>}|null
     */
    public static function operationFor(string $method, array $segments): ?array
    {
        if (self::$templates === null) {
            self::$templates = [];
            foreach (Operations::ALL as $key => $operation) {
                [$templateMethod, $path] = explode(' ', $key, 2);
                self::$templates[$templateMethod][] = ['segments' => explode('/', $path), 'operation' => $operation];
            }
        }
        $best = -1;
        $found = null;
        foreach (self::$templates[$method] ?? [] as $template) {
            if (count($template['segments']) !== count($segments)) {
                continue;
            }
            $literals = 0;
            foreach ($template['segments'] as $i => $segment) {
                if (str_starts_with($segment, '{')) {
                    continue;
                }
                if ($segment !== $segments[$i]) {
                    $literals = -1;
                    break;
                }
                $literals++;
            }
            if ($literals > $best) {
                $best = $literals;
                $found = $template['operation'];
            }
        }

        /** @var array{id: string, retry: ?string, acceptsKey: bool, entries: ?string, lookupSegment: int, noContent: bool, objects: list<string>}|null $found */
        return $found;
    }

    /**
     * @param  array{retry: ?string}  $operation
     */
    public static function safeWithoutKey(array $operation): bool
    {
        return in_array($operation['retry'], self::SAFE_WITHOUT_KEY, true);
    }

    /**
     * Whether a 404 after an attempt that may have reached the API means the delete is done.
     *
     * @param  array{retry: ?string, noContent: bool}  $operation
     */
    public static function notFoundIsDone(array $operation, string $method): bool
    {
        return $operation['noContent'] && $method === 'DELETE'
            && in_array($operation['retry'], ['done', 'converges'], true);
    }

    public static function isIdempotentMethod(string $method): bool
    {
        return in_array($method, ['GET', 'HEAD', 'PUT', 'DELETE'], true);
    }

    /**
     * Whether the failure is worth another attempt. `$safeToRetry` says whether the request
     * can be repeated without risk of doing its work twice.
     */
    public static function shouldRetry(Throwable $error, bool $safeToRetry): bool
    {
        // The API did nothing with the request, so even one unsafe to repeat, such as a POST
        // without a key, can be sent again.
        if (self::neverReached($error)) {
            return true;
        }
        if (! $safeToRetry) {
            return false;
        }
        if ($error instanceof ErrorException) {
            return $error->isRetryable();
        }

        return $error instanceof TransporterException;
    }

    /**
     * Whether the API certainly didn't act on the request: a rate limit refusal, or a
     * connection that was never made.
     */
    public static function neverReached(Throwable $error): bool
    {
        if ($error instanceof ErrorException) {
            return $error->status === 429;
        }

        return $error instanceof TransporterException && $error->neverConnected;
    }

    /**
     * False for a failure the API certainly didn't act on: one that never reached it, or a
     * 503 without a request id, which comes from a load balancer that never passed it on.
     */
    public static function mightHaveRun(Throwable $error): bool
    {
        if (self::neverReached($error)) {
            return false;
        }

        return ! ($error instanceof ErrorException && $error->status === 503 && $error->requestId === null);
    }

    /**
     * Whether the failure came from a response the API sent.
     */
    public static function isApiAnswer(Throwable $error): bool
    {
        return $error instanceof ErrorException || $error instanceof UnserializableResponse;
    }

    /**
     * How many seconds to wait before the next attempt, or null when the API asked for a
     * longer wait than `$maxDelay`.
     */
    public static function backoff(int $attempt, Throwable $error, float $maxDelay): ?float
    {
        if ($error instanceof ErrorException && $error->retryAfter !== null) {
            if ($error->retryAfter > $maxDelay) {
                return null;
            }

            // Jitter only lengthens the wait, so a retry never comes sooner than asked.
            return min($error->retryAfter * (1 + self::random() * 0.25), $maxDelay);
        }

        return min(2 ** ($attempt - 1) * (0.75 + self::random() * 0.5), $maxDelay);
    }

    private static function random(): float
    {
        return mt_rand() / mt_getrandmax();
    }

    /**
     * Reads a Retry-After of decimal seconds or an HTTP date, a date in the past being a wait
     * of 0.
     */
    public static function parseRetryAfter(?string $header, int $now): ?float
    {
        $value = trim($header ?? '');
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d+(\.\d+)?\z/', $value)) {
            return (float) $value;
        }
        // RFC 9110 requires accepting IMF-fixdate and the obsolete RFC 850 and asctime forms.
        $utc = new DateTimeZone('UTC');
        foreach (['D, d M Y H:i:s \G\M\T', 'l, d-M-y H:i:s \G\M\T', 'D M j H:i:s Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, (string) preg_replace('/\s+/', ' ', $value), $utc);
            if ($date !== false) {
                return (float) max(0, $date->getTimestamp() - $now);
            }
        }

        return null;
    }

    /**
     * Whether a call's body changes the value in its path that it looks a contact up by, such
     * as an update of the email it's found by. A retry after the first attempt succeeded
     * would look up the old value, so it isn't safe even though updates converge.
     *
     * @param  array{lookupSegment: int}  $operation
     * @param  list<string>  $segments
     * @param  array<string, mixed>  $query
     */
    public static function changesContactLookup(array $operation, array $segments, array $query, mixed $body): bool
    {
        $idType = $query['idType'] ?? '';
        if ($operation['lookupSegment'] === 0 || ! is_string($idType) || $idType === '' || $idType === 'id') {
            return false;
        }
        if (! is_array($body) || ! array_key_exists($idType, $body)) {
            return false;
        }
        $value = $body[$idType];
        if (! is_string($value)) {
            return true;
        }
        $id = rawurldecode($segments[$operation['lookupSegment']] ?? '');
        // The API matches emails trimmed and ignoring case.
        if ($idType === 'email') {
            return strtolower(trim($value)) !== strtolower(trim($id));
        }

        return $value !== $id;
    }

    /**
     * Gives each entry of the body's `$property` array that has no idempotencyKey a new one,
     * so a retried batch can't repeat an entry. Any other body is returned as is.
     */
    public static function withEntryKeys(mixed $body, string $property): mixed
    {
        if (! is_array($body) || ! is_array($body[$property] ?? null)) {
            return $body;
        }
        foreach ($body[$property] as $i => $entry) {
            if (is_array($entry) && ($entry['idempotencyKey'] ?? null) === null) {
                $body[$property][$i]['idempotencyKey'] = self::uuid();
            }
        }

        return $body;
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
