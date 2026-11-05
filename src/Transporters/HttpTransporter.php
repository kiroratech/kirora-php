<?php

declare(strict_types=1);

namespace Kirora\Transporters;

use Closure;
use GuzzleHttp\ClientInterface as GuzzleClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\RequestOptions;
use InvalidArgumentException;
use JsonException;
use Kirora\Contracts\Transporter;
use Kirora\Exceptions\ErrorException;
use Kirora\Exceptions\TimeoutException;
use Kirora\Exceptions\TransporterException;
use Kirora\Exceptions\UnserializableResponse;
use Kirora\Resource;
use Kirora\ResponseInfo;
use Kirora\Retry;
use Kirora\ValueObjects\Settings;
use Kirora\ValueObjects\Transporter\Payload;
use Kirora\ValueObjects\Transporter\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * Sends calls over HTTP with a PSR-18 client, retrying as each endpoint allows.
 *
 * With a Guzzle client, each attempt is limited to the call's `timeout`. Another PSR-18
 * client is used as it is, so set its timeout on it.
 */
final class HttpTransporter implements Transporter
{
    /** @internal */
    public const DEFAULT_MAX_RESPONSE_BODY = 64 << 20;

    /**
     * The largest response body read, in bytes. With a Guzzle client the transfer stops
     * there; another PSR-18 client has received the whole body before the SDK sees it.
     *
     * @internal
     */
    public static int $maxResponseBody = self::DEFAULT_MAX_RESPONSE_BODY;

    private const NOT_JSON = 'with a body that is not JSON';

    /** Guzzle 8's timeouts: connecting, before the response, and while receiving it. */
    private const GUZZLE_TIMEOUTS = [
        'GuzzleHttp\Exception\ConnectTimeoutException',
        'GuzzleHttp\Exception\NetworkTimeoutException',
        'GuzzleHttp\Exception\ResponseTimeoutException',
    ];

    /** @var Closure(float): void */
    private readonly Closure $sleep;

    /**
     * @param  (Closure(float): void)|null  $sleep  Waits the given seconds between attempts.
     *
     * @internal Create a client with `Kirora::client()`.
     */
    public function __construct(
        private readonly ClientInterface $client,
        private readonly Settings $settings,
        ?Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int) round($seconds * 1_000_000));
            }
        };
    }

    public function withWorkspace(string $externalId): static
    {
        return new self($this->client, $this->settings->withWorkspace($externalId), $this->sleep);
    }

    public function request(Payload $payload): Response
    {
        $settings = $this->settings->forCall($payload->options);
        $prepared = $this->prepare($payload, $settings);

        $mayHaveRun = false;
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->send($prepared, $settings);
            } catch (ErrorException|TransporterException|UnserializableResponse $error) {
                // An attempt that may have run could have deleted it, so a later 404 means done.
                if ($mayHaveRun && $prepared->notFoundIsDone && $error instanceof ErrorException
                    && $error->status === 404 && $error->response !== null) {
                    return new Response(null, $error->response);
                }
                $mayHaveRun = $mayHaveRun || Retry::mightHaveRun($error);
                if ($attempt > $settings->maxRetries || ! Retry::shouldRetry($error, $prepared->safeToRetry)) {
                    throw $error;
                }
                $wait = Retry::backoff($attempt, $error, $settings->maxRetryDelay);
                if ($wait === null) {
                    throw $error;
                }
                ($this->sleep)($wait);
            }
        }
    }

    /**
     * Builds the request once, so every attempt sends the same body and idempotency key, and
     * works out whether it is safe to retry.
     */
    private function prepare(Payload $payload, Settings $settings): PreparedRequest
    {
        $method = strtoupper($payload->method);
        $operation = "$method {$payload->path}";
        $segments = self::checkPath($payload->path, $operation);

        $headers = [];
        foreach ($settings->headers as [$name, $value]) {
            $headers[$name] = $value;
        }
        $headers['Authorization'] = 'Bearer ' . $settings->apiKey;
        $headers['Accept'] = 'application/json';
        $headers['User-Agent'] = $settings->userAgent;

        $endpoint = Retry::operationFor($method, $segments);
        $known = $endpoint !== null;
        $endpoint ??= ['id' => '', 'retry' => null, 'acceptsKey' => false, 'entries' => null, 'lookupSegment' => 0, 'noContent' => false, 'objects' => []];

        $body = $payload->body;
        $encoded = null;
        if ($body !== null) {
            if ($endpoint['entries'] !== null) {
                $body = Retry::withEntryKeys($body, $endpoint['entries']);
            }
            $encoded = self::encode(self::withObjects($body, $endpoint['objects']), $operation);
            $headers['Content-Type'] = 'application/json';
        }

        $key = $settings->idempotencyKey;
        if ($key === null && $endpoint['acceptsKey']) {
            $key = Retry::uuid();
        }
        if ($key !== null) {
            $headers['Idempotency-Key'] = $key;
        }
        if ($settings->workspace !== null) {
            $headers['Kirora-Workspace'] = $settings->workspace;
        }

        // On an endpoint this SDK doesn't know, there's no spec to say whether it takes a key,
        // so the caller's key is trusted to make it safe to retry.
        $keyed = $key !== null && ($endpoint['acceptsKey'] || ! $known);
        $safe = $settings->repeatable
            ?? ($known ? Retry::safeWithoutKey($endpoint) : Retry::isIdempotentMethod($method));

        return new PreparedRequest(
            operation: $operation,
            method: $method,
            url: $settings->baseUrl . $payload->path . self::queryString($payload->rawQuery, $payload->query, $operation),
            headers: $headers,
            body: $encoded,
            format: $payload->format,
            safeToRetry: $keyed || ($safe && ! Retry::changesContactLookup($endpoint, $segments, ['idType' => $payload->queryParam('idType')], $body)),
            // A raw call would have no response to return in its place.
            notFoundIsDone: Retry::notFoundIsDone($endpoint, $method) && $payload->format !== Payload::FORMAT_RAW,
        );
    }

    /**
     * Splits the path into segments, refusing any that would send the request to a different
     * endpoint, as an empty id would.
     *
     * @return list<string>
     */
    private static function checkPath(string $path, string $operation): array
    {
        if (! str_starts_with($path, '/')) {
            throw new InvalidArgumentException("Kirora: $operation: the path must start with \"/\"");
        }
        $segments = explode('/', $path);
        foreach (array_slice($segments, 1) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException("Kirora: $operation: the path can't have an empty, \".\" or \"..\" segment, as from an empty id or a trailing \"/\"");
            }
        }

        return $segments;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private static function queryString(string $rawQuery, array $query, string $operation): string
    {
        $pairs = $rawQuery === '' ? [] : [$rawQuery];
        foreach ($query as $name => $value) {
            foreach (is_array($value) && array_is_list($value) ? $value : [$value] as $item) {
                if ($item === null) {
                    continue;
                }
                if (is_bool($item)) {
                    $item = $item ? 'true' : 'false';
                } elseif (! is_scalar($item)) {
                    throw new InvalidArgumentException("Kirora: $operation: the query parameter \"$name\" must be a string, number, bool or null");
                }
                $pairs[] = rawurlencode((string) $name) . '=' . rawurlencode((string) $item);
            }
        }

        return $pairs === [] ? '' : '?' . implode('&', $pairs);
    }

    /**
     * Turns each empty array at one of `$paths` into an object, so it's sent as `{}`: the spec
     * says the field can only be an object, and PHP can't tell `[]` from `{}`.
     *
     * @param  list<string>  $paths
     */
    private static function withObjects(mixed $body, array $paths): mixed
    {
        foreach ($paths as $path) {
            $body = self::objectAt($body, explode('.', $path));
        }

        return $body;
    }

    /**
     * @param  list<string>  $segments
     */
    private static function objectAt(mixed $value, array $segments): mixed
    {
        $segment = array_shift($segments);
        if (! is_array($value) || $segment === null) {
            return $value;
        }
        $keys = $segment === '*' ? array_keys($value) : (array_key_exists($segment, $value) ? [$segment] : []);
        foreach ($keys as $key) {
            if ($segments === []) {
                if ($value[$key] === []) {
                    $value[$key] = new \stdClass();
                }
            } else {
                $value[$key] = self::objectAt($value[$key], $segments);
            }
        }

        return $value;
    }

    private static function encode(mixed $body, string $operation): string
    {
        // An empty array is the empty object that bodies are.
        if ($body === []) {
            return '{}';
        }
        try {
            return json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $error) {
            throw new InvalidArgumentException("Kirora: $operation: encoding the body: {$error->getMessage()}", 0, $error);
        }
    }

    /**
     * Makes one attempt at the request.
     */
    private function send(PreparedRequest $prepared, Settings $settings): Response
    {
        $raw = $prepared->format === Payload::FORMAT_RAW;
        $request = new Request($prepared->method, $prepared->url, $prepared->headers, $prepared->body);
        // A raw body is the caller's to read, so it isn't limited.
        $sink = $raw ? null : new BoundedSink(self::$maxResponseBody);
        // The response as its headers arrive, so a body stopped for being too large still
        // gives its status and Retry-After.
        $headers = null;
        try {
            if ($this->client instanceof GuzzleClientInterface) {
                $limits = $sink === null ? [] : [
                    RequestOptions::SINK => $sink,
                    RequestOptions::ON_HEADERS => static function (mixed $response) use ($sink, &$headers): void {
                        // Guzzle's MockHandler calls this with an exception it was given, too.
                        if (! $response instanceof ResponseInterface) {
                            return;
                        }
                        $headers = $response;
                        $length = $response->getHeaderLine('Content-Length');
                        if (ctype_digit($length) && (int) $length > $sink->limit) {
                            $sink->overflow();

                            throw new RuntimeException('Kirora: the response is larger than the SDK reads');
                        }
                    },
                ];
                $response = $this->client->send($request, $limits + [
                    // A raw body is read by the caller, so only connecting and each read are limited.
                    RequestOptions::TIMEOUT => $raw ? 0 : $settings->timeout,
                    RequestOptions::CONNECT_TIMEOUT => $settings->timeout,
                    RequestOptions::READ_TIMEOUT => $settings->timeout,
                    RequestOptions::HTTP_ERRORS => false,
                    // The API doesn't redirect, and following one would send the body again to
                    // wherever it points, so a redirect is returned instead.
                    RequestOptions::ALLOW_REDIRECTS => false,
                    RequestOptions::STREAM => $raw,
                ]);
            } else {
                $response = $this->client->sendRequest($request);
            }
        } catch (ConnectException $error) {
            throw self::connectionError($prepared->operation, $error, $settings->timeout, self::neverConnected($error));
        } catch (ClientExceptionInterface|\GuzzleHttp\Exception\GuzzleException $error) {
            // The transfer was stopped for being too large: carry on with what the headers said.
            if (! $sink?->overflowed() || ! $headers instanceof ResponseInterface) {
                throw self::connectionError($prepared->operation, $error, $settings->timeout, false);
            }
            $response = $headers;
        }

        $status = $response->getStatusCode();
        $info = ResponseInfo::fromResponse($response);
        $success = $status >= 200 && $status <= 299;
        if ($status >= 300 && $status <= 399 && $status !== 304) {
            $response->getBody()->close();
            throw new UnserializableResponse($prepared->operation, $status, $info->requestId, sprintf(
                'redirecting to "%s", which the SDK doesn\'t follow',
                $response->getHeaderLine('Location'),
            ));
        }
        if ($raw && $success) {
            return new Response($response, $info);
        }

        if (! $success) {
            // An error whose body is too large, or fails part way, is still built from its
            // status, so it's retried as its status says and honours Retry-After.
            $data = $readError = null;
            try {
                $data = $this->read($response, $prepared->operation, $settings->timeout, $sink);
            } catch (TransporterException $readError) {
            }

            throw ErrorException::fromResponse($info, $data, $readError);
        }
        $data = $this->read($response, $prepared->operation, $settings->timeout, $sink);
        if ($data === null) {
            throw new UnserializableResponse($prepared->operation, $status, $info->requestId, sprintf(
                'with a body larger than %d MiB',
                self::$maxResponseBody >> 20,
            ));
        }
        $noBody = $status === 204 || $prepared->method === 'HEAD' || trim($data) === '';
        if ($noBody && $prepared->format === Payload::FORMAT_JSON) {
            return new Response(null, $info);
        }

        return new Response(self::decode($data, $prepared, $info), $info);
    }

    /**
     * Reads the body, or returns null when it's larger than the limit.
     */
    private function read(ResponseInterface $response, string $operation, float $timeout, ?BoundedSink $sink): ?string
    {
        $body = $response->getBody();
        try {
            // Unless the body was written into the sink as it arrived, copy it in now, so the
            // limit applies the same way.
            if ($body !== $sink && ! $sink?->overflowed()) {
                $sink = new BoundedSink(self::$maxResponseBody);
                if ($body->isSeekable()) {
                    $body->rewind();
                }
                Utils::copyToStream($body, $sink);
            }

            return $sink->contents();
        } catch (RuntimeException $error) {
            throw self::connectionError($operation, $error, $timeout, false);
        } finally {
            try {
                $body->close();
            } catch (Throwable) {
                // A failure to read is the one reported.
            }
        }
    }

    private static function decode(string $data, PreparedRequest $prepared, ResponseInfo $info): mixed
    {
        $problem = fn (string $problem, ?Throwable $previous = null) => new UnserializableResponse(
            $prepared->operation,
            $info->status,
            $info->requestId,
            $problem,
            $previous,
        );
        try {
            $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw $problem(self::NOT_JSON, $error);
        }

        if ($prepared->format === Payload::FORMAT_DATA) {
            if (! is_array($decoded) || ! is_array($decoded['data'] ?? null)) {
                throw $problem('without an object or array in a `data` envelope');
            }

            return $decoded['data'];
        }
        if ($prepared->format === Payload::FORMAT_PAGE) {
            $pagination = is_array($decoded) ? ($decoded['meta']['pagination'] ?? null) : null;
            if (
                ! is_array($decoded) || ! is_array($decoded['data'] ?? null) || ! array_is_list($decoded['data'])
                || ! is_array($pagination) || ! (is_int($pagination['limit'] ?? null) || is_float($pagination['limit'] ?? null))
                || ! array_key_exists('nextCursor', $pagination)
                || ! ($pagination['nextCursor'] === null || is_string($pagination['nextCursor']))
            ) {
                throw $problem('without a page of `data` and `meta.pagination`');
            }
            // Every list the API pages is of objects.
            if (! Resource::areObjects($decoded['data'])) {
                throw $problem('with an item in its page that is not an object');
            }
        }

        return $decoded;
    }

    private static function neverConnected(ConnectException $error): bool
    {
        $context = self::handlerContext($error);
        if ($context === null) {
            // Guzzle 8 throws a ConnectException only when the connection was never made: a
            // failure after it was is a NetworkException, and a timeout has its own class.
            return true;
        }
        // Guzzle 7 throws it for most cURL failures, so cURL's error number says which.
        $errno = $context['errno'] ?? null;
        if ($errno === null) {
            // Without cURL, a ConnectException is only thrown before the request was sent,
            // unless the attempt ran out of time, which may have been after.
            return ! self::isTimeout($error);
        }
        if ($errno === 28) {
            // cURL's timeout, which covers connecting too: nothing was sent if the transfer
            // never got as far as starting.
            $pretransfer = $context['pretransfer_time'] ?? null;

            return (is_int($pretransfer) || is_float($pretransfer)) && $pretransfer == 0;
        }

        // cURL's couldn't resolve the proxy, couldn't resolve the host, and couldn't connect,
        // which includes the OS giving up on connecting.
        return in_array($errno, [5, 6, 7], true);
    }

    private static function isTimeout(Throwable $error): bool
    {
        foreach (self::GUZZLE_TIMEOUTS as $class) {
            if (is_a($error, $class)) {
                return true;
            }
        }
        $errno = null;
        if ($error instanceof ConnectException || $error instanceof RequestException) {
            $context = self::handlerContext($error);
            if ($context === null) {
                // Guzzle 8, which names every timeout it detects by class.
                return false;
            }
            $errno = $context['errno'] ?? null;
        }
        if ($errno !== null) {
            return $errno === 28;
        }

        return preg_match('/timed out|timeout/i', $error->getMessage()) === 1;
    }

    /**
     * The cURL details of a Guzzle 7 failure, or null on Guzzle 8, which has none.
     *
     * @return array<string, mixed>|null
     */
    private static function handlerContext(Throwable $error): ?array
    {
        return method_exists($error, 'getHandlerContext') ? (array) $error->getHandlerContext() : null;
    }

    private static function connectionError(string $operation, Throwable $error, float $timeout, bool $neverConnected): TransporterException
    {
        if (self::isTimeout($error)) {
            return new TimeoutException($operation, "Kirora: $operation timed out after {$timeout}s", $error, $neverConnected);
        }

        return new TransporterException($operation, "Kirora: $operation failed: {$error->getMessage()}", $error, $neverConnected);
    }
}
