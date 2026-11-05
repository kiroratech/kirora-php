<?php

declare(strict_types=1);

namespace Kirora\ValueObjects;

use InvalidArgumentException;
use Kirora;

/**
 * A client's settings, or one call's, once its options are applied. Immutable, so a
 * client's workspace copies and calls can't change it.
 *
 * @internal
 */
final class Settings
{
    public const DEFAULT_BASE_URL = 'https://api.kirora.io';

    public const DEFAULT_TIMEOUT = 30.0;

    public const DEFAULT_MAX_RETRIES = 3;

    public const DEFAULT_MAX_RETRY_DELAY = 30.0;

    private const MAX_RETRIES_LIMIT = 10;

    private const CLIENT_OPTIONS = ['baseUrl', 'httpClient', 'timeout', 'maxRetries', 'maxRetryDelay', 'workspace', 'headers', 'appInfo'];

    private const CALL_OPTIONS = ['idempotencyKey', 'workspace', 'headers', 'timeout', 'maxRetries', 'maxRetryDelay', 'repeatable'];

    /** Headers the SDK sets itself, which `headers` can't replace. */
    private const OWN_HEADERS = ['authorization', 'accept', 'user-agent', 'content-type', 'idempotency-key', 'kirora-workspace'];

    /**
     * @param  array<string, array{0: string, 1: string}>  $headers  By lowercase name: the name as
     *                                                              given, and its value.
     */
    private function __construct(
        public readonly string $apiKey,
        public readonly string $baseUrl,
        public readonly float $timeout,
        public readonly int $maxRetries,
        public readonly float $maxRetryDelay,
        public readonly ?string $workspace,
        public readonly array $headers,
        public readonly string $userAgent,
        public readonly ?string $idempotencyKey = null,
        public readonly ?bool $repeatable = null,
    ) {
    }

    /**
     * The client's settings. An API key that's null or empty is read from `KIRORA_API_KEY`,
     * and `KIRORA_BASE_URL`, when set, is used unless `baseUrl` is passed.
     *
     * @param  array<string, mixed>  $options
     */
    public static function fromOptions(?string $apiKey, array $options): self
    {
        self::checkNames($options, self::CLIENT_OPTIONS, 'Kirora::client()');
        if ($apiKey === null || $apiKey === '') {
            $apiKey = self::env('KIRORA_API_KEY') ?? '';
        }
        // Spaces and quotes around the key are ignored, as from a pasted .env value.
        $apiKey = trim(trim(trim($apiKey), '"\''));
        // "undefined" and "null" are what a templated env or .env file renders for an unset value.
        if ($apiKey === '' || $apiKey === 'undefined' || $apiKey === 'null') {
            throw new InvalidArgumentException('Kirora: an API key is required: pass it, or set KIRORA_API_KEY');
        }

        if (isset($options['baseUrl'])) {
            $baseUrl = self::baseUrl($options['baseUrl'], 'baseUrl');
        } elseif (($fromEnv = self::env('KIRORA_BASE_URL')) !== null) {
            $baseUrl = self::baseUrl($fromEnv, 'KIRORA_BASE_URL');
        } else {
            $baseUrl = self::DEFAULT_BASE_URL;
        }

        $userAgent = 'kirora-php/' . Kirora::VERSION . ' php/' . PHP_VERSION;
        if (isset($options['appInfo'])) {
            $userAgent .= ' ' . self::appInfo($options['appInfo']);
        }

        [$headers, $workspace, $key] = self::headers($options['headers'] ?? [], [], null);
        // Every call from the client would share the key, so the second create would replay the
        // first's response.
        if ($key !== null) {
            throw new InvalidArgumentException('Kirora: an Idempotency-Key header must be passed to a call, not to Kirora::client()');
        }
        if (isset($options['workspace'])) {
            $workspace = self::workspace($options['workspace']);
        }

        return new self(
            apiKey: $apiKey,
            baseUrl: $baseUrl,
            timeout: self::timeout($options['timeout'] ?? self::DEFAULT_TIMEOUT),
            maxRetries: self::maxRetries($options['maxRetries'] ?? self::DEFAULT_MAX_RETRIES),
            maxRetryDelay: self::maxRetryDelay($options['maxRetryDelay'] ?? self::DEFAULT_MAX_RETRY_DELAY),
            workspace: $workspace,
            headers: $headers,
            userAgent: $userAgent,
        );
    }

    /**
     * A copy that acts in the partner workspace with this `externalId`.
     */
    public function withWorkspace(string $externalId): self
    {
        return $this->copy(workspace: self::workspace($externalId));
    }

    /**
     * The settings for one call, with its options over the client's.
     *
     * @param  array<string, mixed>  $options
     */
    public function forCall(array $options): self
    {
        self::checkNames($options, self::CALL_OPTIONS, 'a call');
        [$headers, $workspace, $key] = self::headers($options['headers'] ?? [], $this->headers, $this->workspace);
        // An option set to null is left at the client's setting, as if it weren't passed.
        $options = array_filter($options, fn ($value) => $value !== null);
        if (array_key_exists('idempotencyKey', $options)) {
            $key = $options['idempotencyKey'];
            if (! is_string($key) || $key === '') {
                throw new InvalidArgumentException('Kirora: idempotencyKey must be a non-empty string');
            }
        }
        if (array_key_exists('workspace', $options)) {
            $workspace = self::workspace($options['workspace']);
        }
        $repeatable = $options['repeatable'] ?? null;
        if ($repeatable !== null && ! is_bool($repeatable)) {
            throw new InvalidArgumentException('Kirora: repeatable must be a bool');
        }

        return $this->copy(
            timeout: array_key_exists('timeout', $options) ? self::timeout($options['timeout']) : $this->timeout,
            maxRetries: array_key_exists('maxRetries', $options) ? self::maxRetries($options['maxRetries']) : $this->maxRetries,
            maxRetryDelay: array_key_exists('maxRetryDelay', $options) ? self::maxRetryDelay($options['maxRetryDelay']) : $this->maxRetryDelay,
            workspace: $workspace,
            headers: $headers,
            idempotencyKey: $key,
            repeatable: $repeatable,
        );
    }

    /**
     * @param  array<string, array{0: string, 1: string}>|null  $headers
     */
    private function copy(
        ?float $timeout = null,
        ?int $maxRetries = null,
        ?float $maxRetryDelay = null,
        ?string $workspace = null,
        ?array $headers = null,
        ?string $idempotencyKey = null,
        ?bool $repeatable = null,
    ): self {
        return new self(
            apiKey: $this->apiKey,
            baseUrl: $this->baseUrl,
            timeout: $timeout ?? $this->timeout,
            maxRetries: $maxRetries ?? $this->maxRetries,
            maxRetryDelay: $maxRetryDelay ?? $this->maxRetryDelay,
            workspace: $workspace ?? $this->workspace,
            headers: $headers ?? $this->headers,
            userAgent: $this->userAgent,
            idempotencyKey: $idempotencyKey,
            repeatable: $repeatable,
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  list<string>  $known
     */
    private static function checkNames(array $options, array $known, string $where): void
    {
        $unknown = array_diff(array_keys($options), $known);
        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'Kirora: %s doesn\'t take the option %s. It takes %s.',
                $where,
                implode(', ', array_map(fn ($name) => "\"$name\"", $unknown)),
                implode(', ', $known),
            ));
        }
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            $value = $_ENV[$name] ?? $_SERVER[$name] ?? null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function baseUrl(mixed $url, string $from): string
    {
        $parts = is_string($url) ? parse_url($url) : false;
        if (
            ! is_string($url) || $parts === false
            || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || ($parts['host'] ?? '') === '' || strpbrk($url, '?#') !== false
        ) {
            throw new InvalidArgumentException(sprintf(
                'Kirora: %s must be an http or https URL without a query or fragment, not %s',
                $from,
                is_string($url) ? "\"$url\"" : get_debug_type($url),
            ));
        }

        return rtrim($url, '/');
    }

    private static function timeout(mixed $seconds): float
    {
        if (! is_int($seconds) && ! is_float($seconds) || ! is_finite((float) $seconds) || $seconds <= 0) {
            throw new InvalidArgumentException('Kirora: timeout must be a positive number of seconds');
        }

        return (float) $seconds;
    }

    private static function maxRetries(mixed $count): int
    {
        if (! is_int($count) || $count < 0 || $count > self::MAX_RETRIES_LIMIT) {
            throw new InvalidArgumentException('Kirora: maxRetries must be a whole number from 0 to ' . self::MAX_RETRIES_LIMIT);
        }

        return $count;
    }

    private static function maxRetryDelay(mixed $seconds): float
    {
        if (! is_int($seconds) && ! is_float($seconds) || ! is_finite((float) $seconds) || $seconds < 0) {
            throw new InvalidArgumentException('Kirora: maxRetryDelay must be a number of seconds of at least 0');
        }

        return (float) $seconds;
    }

    private static function workspace(mixed $externalId): string
    {
        // An empty workspace must fail rather than act in the key's own workspace.
        if (! is_string($externalId) || $externalId === '') {
            throw new InvalidArgumentException('Kirora: workspace must be a workspace\'s externalId, not an empty string');
        }

        return $externalId;
    }

    private static function appInfo(mixed $appInfo): string
    {
        $name = is_array($appInfo) ? ($appInfo['name'] ?? null) : null;
        $version = is_array($appInfo) ? ($appInfo['version'] ?? null) : null;
        $token = '/^[A-Za-z0-9_.-]+\z/';
        if (! is_string($name) || ! preg_match($token, $name) || ($version !== null && (! is_string($version) || ! preg_match($token, $version)))) {
            throw new InvalidArgumentException('Kirora: appInfo must be [\'name\' => ..., \'version\' => ...], each of letters, digits and .-_');
        }

        return $version === null ? $name : "$name/$version";
    }

    /**
     * Merges `$given` over `$base`. An Idempotency-Key header is returned as the key, and a
     * Kirora-Workspace header as the workspace.
     *
     * @param  array<string, array{0: string, 1: string}>  $base
     * @return array{0: array<string, array{0: string, 1: string}>, 1: ?string, 2: ?string}
     */
    private static function headers(mixed $given, array $base, ?string $workspace): array
    {
        if (! is_array($given)) {
            throw new InvalidArgumentException('Kirora: headers must be an array of names to values');
        }
        $key = null;
        foreach ($given as $name => $value) {
            // A header value may hold tabs and visible characters only, as RFC 9110 says.
            if (! is_string($name) || ! preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+\z/', $name)
                || ! is_string($value) || preg_match('/[^\t\x20-\x7E\x80-\xFF]/', $value)) {
                throw new InvalidArgumentException('Kirora: headers must map valid header names to string values');
            }
            $lower = strtolower($name);
            if ($lower === 'idempotency-key') {
                if ($value === '') {
                    throw new InvalidArgumentException('Kirora: an Idempotency-Key header needs a key, not an empty string');
                }
                $key = $value;
            } elseif ($lower === 'kirora-workspace') {
                $workspace = self::workspace($value);
            } elseif (! in_array($lower, self::OWN_HEADERS, true)) {
                $base[$lower] = [$name, $value];
            }
        }

        return [$base, $workspace, $key];
    }
}
