<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Kirora\Exceptions\ErrorException;
use Kirora\Exceptions\TimeoutException;
use Kirora\Exceptions\TransporterException;
use Kirora\Exceptions\UnserializableResponse;
use Kirora\Transporters\HttpTransporter;

/**
 * A failure to reach the API as the installed Guzzle reports it, for cURL error `$errno`:
 * - `connect`: the connection was never made.
 * - `connect-timeout`: it timed out connecting, before sending anything.
 * - `timeout`: it timed out, maybe after sending.
 * - `network`: it failed, maybe after sending.
 */
function transferFailure(string $kind, int $errno, string $message): GuzzleHttp\Exception\TransferException
{
    $request = new Request('GET', '/');
    $message = "cURL error $errno: $message";
    if (method_exists(ConnectException::class, 'getHandlerContext')) {
        // Guzzle 7: a ConnectException, which cURL's error number and timing explain.
        $context = ['errno' => $errno] + ($kind === 'connect-timeout' ? ['pretransfer_time' => 0.0] : []);

        return new ConnectException($message, $request, null, $context);
    }
    $class = [
        'connect' => ConnectException::class,
        'connect-timeout' => 'GuzzleHttp\Exception\ConnectTimeoutException',
        'timeout' => 'GuzzleHttp\Exception\NetworkTimeoutException',
        'network' => 'GuzzleHttp\Exception\NetworkException',
    ][$kind];

    return new $class($message, $request);
}

const CONTACT = ['id' => 'c1', 'email' => 'ada@example.com'];

afterEach(function () {
    HttpTransporter::$maxResponseBody = HttpTransporter::DEFAULT_MAX_RESPONSE_BODY;
});

it('sends the SDK\'s headers', function () {
    $api = fakeApi([data(CONTACT)], ['headers' => ['X-Trace' => 't1', 'Authorization' => 'Bearer other']]);
    $api->client->contacts->identify(['email' => 'ada@example.com']);
    $request = $api->request();

    expect($request->getMethod())->toBe('POST')
        ->and((string) $request->getUri())->toBe('https://api.test/v1/contacts/identify')
        ->and($request->getHeaderLine('Authorization'))->toBe('Bearer test_key')
        ->and($request->getHeaderLine('Accept'))->toBe('application/json')
        ->and($request->getHeaderLine('Content-Type'))->toBe('application/json')
        ->and($request->getHeaderLine('User-Agent'))->toStartWith('kirora-php/')
        ->and($request->getHeaderLine('X-Trace'))->toBe('t1')
        ->and($request->getHeaderLine('Idempotency-Key'))->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')
        ->and($api->body())->toBe(['email' => 'ada@example.com']);
});

it('encodes ids and the query', function () {
    $api = fakeApi([data(CONTACT)]);
    $api->client->contacts->get('ada+1@example.com/x', ['idType' => 'email']);
    expect((string) $api->request()->getUri())
        ->toBe('https://api.test/v1/contacts/ada%2B1%40example.com%2Fx?idType=email');
});

it('leaves out null query values and writes bools as words', function () {
    $api = fakeApi([page([])]);
    $api->client->messages->list(['status' => null, 'unread' => true, 'limit' => 10]);
    expect($api->request()->getUri()->getQuery())->toBe('unread=true&limit=10');
});

it('refuses an empty id rather than calling another endpoint', function () {
    fakeApi([])->client->contacts->get('');
})->throws(InvalidArgumentException::class, 'empty, "." or ".." segment');

it('sends an empty body as an object', function () {
    $api = fakeApi([data(['id' => 'l1'])]);
    $api->client->lists->update('l1', []);
    expect((string) $api->request()->getBody())->toBe('{}');
});

it('sends no body or key where the endpoint takes none', function () {
    $api = fakeApi([data(CONTACT)]);
    $api->client->contacts->get('c1');
    expect($api->request()->hasHeader('Content-Type'))->toBeFalse()
        ->and($api->request()->hasHeader('Idempotency-Key'))->toBeFalse()
        ->and((string) $api->request()->getBody())->toBe('');
});

it('takes per-call options over the client\'s', function () {
    $api = fakeApi([data([])], ['workspace' => 'a', 'headers' => ['X-A' => '1', 'X-B' => '1']]);
    $api->client->events->track(['name' => 'x'], [
        'idempotencyKey' => 'my-key',
        'workspace' => 'b',
        'headers' => ['X-B' => '2'],
    ]);
    $request = $api->request();
    expect($request->getHeaderLine('Idempotency-Key'))->toBe('my-key')
        ->and($request->getHeaderLine('Kirora-Workspace'))->toBe('b')
        ->and($request->getHeaderLine('X-A'))->toBe('1')
        ->and($request->getHeaderLine('X-B'))->toBe('2');
});

it('treats Idempotency-Key and Kirora-Workspace call headers as the options', function () {
    $api = fakeApi([data([])]);
    $api->client->events->track(['name' => 'x'], ['headers' => ['idempotency-key' => 'k', 'kirora-workspace' => 'w']]);
    expect($api->request()->getHeaderLine('Idempotency-Key'))->toBe('k')
        ->and($api->request()->getHeaderLine('Kirora-Workspace'))->toBe('w');
});

it('refuses unknown call options', function () {
    fakeApi([])->client->contacts->get('c1', [], ['signal' => true]);
})->throws(InvalidArgumentException::class, 'doesn\'t take the option "signal"');

it('retries a read after a 5xx with backoff', function () {
    $api = fakeApi([apiError(503, 'SERVICE_UNAVAILABLE', headers: ['X-Request-Id' => 'r1']), apiError(500, 'INTERNAL_ERROR'), data(CONTACT)]);
    $contact = $api->client->contacts->get('c1');
    expect($contact->id)->toBe('c1')
        ->and($api->count())->toBe(3)
        ->and($api->sleeps)->toHaveCount(2)
        ->and($api->sleeps[0])->toBeGreaterThanOrEqual(0.75)->toBeLessThanOrEqual(1.25)
        ->and($api->sleeps[1])->toBeGreaterThanOrEqual(1.5)->toBeLessThanOrEqual(2.5);
});

it('reuses the idempotency key on every attempt', function () {
    $api = fakeApi([apiError(500, 'INTERNAL_ERROR'), data(['id' => 'e1'], 201)]);
    $api->client->events->track(['name' => 'x']);
    expect($api->count())->toBe(2)
        ->and($api->request(0)->getHeaderLine('Idempotency-Key'))
        ->toBe($api->request(1)->getHeaderLine('Idempotency-Key'))
        ->and((string) $api->request(0)->getBody())->toBe((string) $api->request(1)->getBody());
});

it('makes a key per call', function () {
    $api = fakeApi([data(['id' => 'e1'], 201), data(['id' => 'e2'], 201)]);
    $api->client->events->track(['name' => 'x']);
    $api->client->events->track(['name' => 'x']);
    expect($api->request(0)->getHeaderLine('Idempotency-Key'))
        ->not->toBe($api->request(1)->getHeaderLine('Idempotency-Key'));
});

it('gives each batch entry without a key its own', function () {
    $api = fakeApi([apiError(502, 'INTERNAL_ERROR'), data(['results' => []])]);
    $api->client->events->trackBatch(['events' => [['name' => 'a'], ['name' => 'b', 'idempotencyKey' => 'mine']]]);
    $first = $api->body(0)['events'];
    expect($first[0]['idempotencyKey'])->toBeString()
        ->and($first[1]['idempotencyKey'])->toBe('mine')
        ->and($api->body(1)['events'])->toBe($first);
});

it('doesn\'t retry an unsafe write without a key after a 5xx', function () {
    $api = fakeApi([apiError(500, 'INTERNAL_ERROR')]);
    expect(fn () => $api->client->request('POST', '/v1/new-endpoint', ['a' => 1]))->toThrow(ErrorException::class);
    expect($api->count())->toBe(1);
});

it('retries an unknown write with a key or when repeatable', function (array $options) {
    $api = fakeApi([apiError(500, 'INTERNAL_ERROR'), json(200, ['ok' => true])]);
    expect($api->client->request('POST', '/v1/new-endpoint', ['a' => 1], $options))->toBe(['ok' => true]);
    expect($api->count())->toBe(2);
})->with([[['idempotencyKey' => 'k']], [['repeatable' => true]]]);

it('always retries a rate limit, honouring Retry-After', function () {
    $api = fakeApi([apiError(429, 'RATE_LIMITED', headers: ['Retry-After' => '2']), json(200, [])]);
    $api->client->request('POST', '/v1/new-endpoint', ['a' => 1]);
    expect($api->count())->toBe(2)
        ->and($api->sleeps[0])->toBeGreaterThanOrEqual(2.0)->toBeLessThanOrEqual(2.5);
});

it('gives up when Retry-After is longer than maxRetryDelay', function () {
    $api = fakeApi([apiError(429, 'RATE_LIMITED', headers: ['Retry-After' => '60'])]);
    try {
        $api->client->contacts->get('c1');
        $this->fail('expected an error');
    } catch (ErrorException $error) {
        expect($error->retryAfter)->toBe(60.0)->and($api->count())->toBe(1);
    }
});

it('stops after maxRetries', function () {
    $api = fakeApi(array_map(fn () => apiError(503, 'SERVICE_UNAVAILABLE'), range(1, 3)), ['maxRetries' => 2]);
    expect(fn () => $api->client->contacts->get('c1'))->toThrow(ErrorException::class);
    expect($api->count())->toBe(3);
});

it('takes maxRetries per call', function () {
    $api = fakeApi([apiError(503, 'SERVICE_UNAVAILABLE')]);
    expect(fn () => $api->client->contacts->get('c1', [], ['maxRetries' => 0]))->toThrow(ErrorException::class);
    expect($api->count())->toBe(1);
});

it('doesn\'t retry a 4xx', function () {
    $api = fakeApi([apiError(404, 'NOT_FOUND')]);
    expect(fn () => $api->client->contacts->get('c1'))->toThrow(ErrorException::class);
    expect($api->count())->toBe(1);
});

it('retries IDEMPOTENCY_KEY_IN_USE when the call has a key', function () {
    $api = fakeApi([apiError(409, 'IDEMPOTENCY_KEY_IN_USE'), data(['id' => 'e1'], 201)]);
    $api->client->events->track(['name' => 'x']);
    expect($api->count())->toBe(2);
});

it('retries a connection that was never made, even for an unsafe write', function () {
    $api = fakeApi([transferFailure('connect', 7, 'Failed to connect'), json(200, [])]);
    $api->client->request('POST', '/v1/new-endpoint', ['a' => 1]);
    expect($api->count())->toBe(2);
});

it('doesn\'t retry an unsafe write that timed out, since it may have run', function () {
    $api = fakeApi([transferFailure('timeout', 28, 'Operation timed out')]);
    try {
        $api->client->request('POST', '/v1/new-endpoint', ['a' => 1]);
        $this->fail('expected a timeout');
    } catch (TimeoutException $error) {
        expect($error->getMessage())->toBe('Kirora: POST /v1/new-endpoint timed out after 30s')
            ->and($error->neverConnected)->toBeFalse()
            ->and($error->getPrevious())->toBeInstanceOf(GuzzleHttp\Exception\TransferException::class);
    }
    expect($api->count())->toBe(1);
});

it('retries a read after a timeout', function () {
    $api = fakeApi([transferFailure('timeout', 28, 'Operation timed out'), data(CONTACT)]);
    expect($api->client->contacts->get('c1')->id)->toBe('c1');
});

it('throws a TransporterException when it can\'t connect', function () {
    $api = fakeApi(array_map(fn () => transferFailure('connect', 6, 'Could not resolve host'), range(1, 4)));
    expect(fn () => $api->client->contacts->get('c1'))
        ->toThrow(TransporterException::class, 'Kirora: GET /v1/contacts/c1 failed: cURL error 6: Could not resolve host');
});

it('treats a 404 after a delete that may have run as done', function () {
    $api = fakeApi([transferFailure('network', 52, 'Empty reply from server'), apiError(404, 'NOT_FOUND')]);
    $api->client->lists->delete('l1');
    expect($api->count())->toBe(2);
});

it('still throws a 404 on a first delete', function () {
    $api = fakeApi([apiError(404, 'NOT_FOUND')]);
    $api->client->lists->delete('l1');
})->throws(ErrorException::class);

it('doesn\'t retry an update that changes the email the contact is found by', function () {
    $api = fakeApi([apiError(500, 'INTERNAL_ERROR')]);
    expect(fn () => $api->client->contacts->update('ada@example.com', ['email' => 'new@example.com'], ['idType' => 'email']))
        ->toThrow(ErrorException::class);
    expect($api->count())->toBe(1);
});

it('retries an update that keeps the email the contact is found by', function () {
    $api = fakeApi([apiError(500, 'INTERNAL_ERROR'), data(CONTACT)]);
    $api->client->contacts->update('Ada@Example.com', ['email' => ' ada@example.com', 'firstName' => 'Ada'], ['idType' => 'email']);
    expect($api->count())->toBe(2);
});

it('refuses to follow a redirect', function () {
    $api = fakeApi([new Response(302, ['Location' => 'https://elsewhere.test'])]);
    $api->client->contacts->get('c1');
})->throws(UnserializableResponse::class, 'redirecting to "https://elsewhere.test"');

it('throws UnserializableResponse for a body that isn\'t JSON', function () {
    $api = fakeApi([new Response(200, ['X-Request-Id' => 'r9'], '<html>')]);
    $api->client->contacts->get('c1');
})->throws(UnserializableResponse::class, 'Kirora: GET /v1/contacts/c1 returned 200 with a body that is not JSON [request r9]');

it('throws UnserializableResponse without a data envelope', function () {
    $api = fakeApi([json(200, ['id' => 'c1'])]);
    $api->client->contacts->get('c1');
})->throws(UnserializableResponse::class, 'without an object or array in a `data` envelope');

it('throws UnserializableResponse for a body over the limit', function () {
    HttpTransporter::$maxResponseBody = 16;
    fakeApi([data(CONTACT)])->client->contacts->get('c1');
})->throws(UnserializableResponse::class, 'with a body larger than 0 MiB');

it('still makes an error from a body over the limit', function () {
    HttpTransporter::$maxResponseBody = 16;
    expect(fn () => fakeApi([apiError(404, 'GONE_FOR_GOOD')])->client->contacts->get('c1'))
        ->toThrow(fn (ErrorException $error) => expect($error->errorCode)->toBe('NOT_FOUND'));
});

it('returns null for a request with no body', function () {
    $api = fakeApi([new Response(204)]);
    expect($api->client->request('DELETE', '/v1/lists/l1'))->toBeNull();
});

it('passes a query string in request()', function () {
    $api = fakeApi([json(200, ['data' => []])]);
    $api->client->request('GET', '/v1/segments?limit=5&cursor=abc');
    expect($api->request()->getUri()->getQuery())->toBe('limit=5&cursor=abc');
});

it('unwraps data in requestData()', function () {
    $api = fakeApi([data(['id' => 's1'])]);
    expect($api->client->requestData('GET', '/v1/segments/s1'))->toBe(['id' => 's1']);
});

it('reports the response a result came from', function () {
    $api = fakeApi([data(['id' => 'm1'], 202, [
        'X-Request-Id' => 'req_1',
        'Idempotency-Replay' => 'true',
        'RateLimit-Limit' => '100',
        'RateLimit-Remaining' => '99',
        'RateLimit-Reset' => '30',
    ])]);
    $sent = $api->client->transactional->send(['to' => ['email' => 'a@example.com']]);
    $response = $sent->lastResponse();
    expect($response->status)->toBe(202)
        ->and($response->requestId)->toBe('req_1')
        ->and($response->idempotencyReplay)->toBeTrue()
        ->and($response->rateLimit?->limit)->toBe(100)
        ->and($response->rateLimit?->remaining)->toBe(99)
        ->and($response->rateLimit?->resetSeconds)->toBe(30)
        ->and($response->header('x-request-id'))->toBe('req_1');
});

it('leaves the rate limit null when a header is invalid', function () {
    $api = fakeApi([data(CONTACT, 200, ['RateLimit-Limit' => '100', 'RateLimit-Remaining' => '-1', 'RateLimit-Reset' => '30'])]);
    expect($api->client->contacts->get('c1')->lastResponse()->rateLimit)->toBeNull();
});

it('sends an empty array as {} where the spec says the field is an object', function () {
    $api = fakeApi([data(['id' => 'e1'], 201), data(['results' => []]), data(CONTACT)]);
    $api->client->events->track(['name' => 'x', 'properties' => [], 'tags' => []]);
    $api->client->events->trackBatch(['events' => [['event' => ['name' => 'x', 'properties' => []]]]]);
    $api->client->contacts->update('c1', ['attributes' => [], 'firstName' => 'Ada']);

    expect((string) $api->request(0)->getBody())->toBe('{"name":"x","properties":{},"tags":[]}')
        ->and((string) $api->request(1)->getBody())->toContain('"properties":{}')
        ->and((string) $api->request(2)->getBody())->toBe('{"attributes":{},"firstName":"Ada"}');
});

it('sends an empty array as {} in nested rules too', function () {
    $api = fakeApi([data(['id' => 's1'], 201)]);
    $api->client->segments->create(['name' => 's', 'rules' => ['all' => [['any' => [['within' => [], 'values' => []]]]]]]);

    expect((string) $api->request()->getBody())->toBe('{"name":"s","rules":{"all":[{"any":[{"within":{},"values":[]}]}]}}');
});

it('knows the object fields of conditions however deep the spec nests them', function () {
    // An A/B test version's template block nests its conditions deepest of all.
    $objects = Kirora\Operations::ALL['POST /v1/campaigns']['objects'];
    expect($objects)->toContain('abTest.versions.*.email.templateSnapshot.blocks.*.visibleIf.any.*.any.*.within')
        ->and($objects)->toContain('content.email.templateSnapshot.blocks.*.visibleIf.all.*.all.*.within');
});

it('keeps the query string of a path as written, and pages through it', function () {
    $api = fakeApi([page([['id' => 'a']], 'p2'), page([['id' => 'b']])]);
    $items = iterator_to_array($api->client->requestAll('/v1/attributes/k/values?limit=50&filter.status=sent&tag=a&tag=b&f[x]=1'), false);
    expect($items)->toHaveCount(2)
        ->and($api->request(0)->getUri()->getQuery())->toBe('limit=50&filter.status=sent&tag=a&tag=b&f%5Bx%5D=1')
        ->and($api->request(1)->getUri()->getQuery())->toBe('limit=50&filter.status=sent&tag=a&tag=b&f%5Bx%5D=1&cursor=p2');
});

it('replaces a cursor written into the path when it pages', function () {
    $api = fakeApi([page([['id' => 'a']], 'p2'), page([])]);
    iterator_to_array($api->client->requestAll('/v1/segments?cursor=p1'));
    expect($api->request(1)->getUri()->getQuery())->toBe('cursor=p2');
});

it('reads idType from a query string written into the path', function () {
    $api = fakeApi([apiError(500, 'INTERNAL_ERROR')]);
    expect(fn () => $api->client->request('PATCH', '/v1/contacts/ada%40example.com?idType=email', ['email' => 'new@example.com']))
        ->toThrow(ErrorException::class);
    expect($api->count())->toBe(1);
});

it('retries a connect that timed out before anything was sent', function () {
    $api = fakeApi([
        transferFailure('connect', 7, 'Failed to connect: Connection timed out'),
        transferFailure('connect-timeout', 28, 'Connection timed out after 30001 milliseconds'),
        json(200, []),
    ]);
    $api->client->request('POST', '/v1/new-endpoint', ['a' => 1]);
    expect($api->count())->toBe(3);
});

it('reports a connect failure that mentions a timeout as a connection failure, not a timeout', function () {
    $api = fakeApi(array_map(fn () => transferFailure('connect', 7, 'Connection timed out'), range(1, 4)));
    try {
        $api->client->contacts->get('c1');
        $this->fail('expected an error');
    } catch (TransporterException $error) {
        expect($error)->not->toBeInstanceOf(TimeoutException::class)->and($error->neverConnected)->toBeTrue();
    }
});

it('treats null call options as the client\'s', function () {
    $api = fakeApi([data(CONTACT)], ['workspace' => 'w']);
    $api->client->contacts->get('c1', [], ['timeout' => null, 'maxRetries' => null, 'workspace' => null, 'idempotencyKey' => null]);
    expect($api->request()->getHeaderLine('Kirora-Workspace'))->toBe('w');
});

it('refuses header names and values with control characters, before sending', function (array $headers) {
    Kirora::client('key', ['headers' => $headers]);
})->with([[['X-A' => "a\x01"]], [["X-A\n" => 'a']], [['X-A' => "a\n"]]])->throws(InvalidArgumentException::class);

it('refuses app info ending in a newline', function () {
    Kirora::client('key', ['appInfo' => ['name' => "app\n"]]);
})->throws(InvalidArgumentException::class, 'appInfo');

it('throws UnserializableResponse when a list method gets something else', function (mixed $data) {
    fakeApi([data($data)])->client->contacts->topics->list('c1');
})->with([[['t1']], [['topics' => []]]])->throws(Kirora\Exceptions\UnserializableResponse::class, 'without an array of objects');

/**
 * A body that fails part way through, as a connection dropped while it's read does. Sets
 * `$state->closed` when it's closed.
 */
function failingBody(stdClass $state = new stdClass()): Psr\Http\Message\StreamInterface
{
    $state->closed = false;

    return GuzzleHttp\Psr7\FnStream::decorate(GuzzleHttp\Psr7\Utils::streamFor(''), [
        'eof' => fn () => false,
        'read' => fn () => throw new RuntimeException('Connection reset while reading'),
        'close' => function () use ($state) {
            $state->closed = true;
        },
    ]);
}

it('keeps the status, request id and Retry-After of an error whose body fails to read', function () {
    $state = new stdClass();
    $api = fakeApi([new Response(429, ['Retry-After' => '60', 'X-Request-Id' => 'req_1'], failingBody($state)), data(CONTACT)]);
    // Asked to wait longer than maxRetryDelay, so it isn't retried sooner than allowed.
    expect(fn () => $api->client->contacts->get('c1'))->toThrow(fn (ErrorException $error) => expect($error->status)->toBe(429)
        ->and($error->errorCode)->toBe('RATE_LIMITED')
        ->and($error->requestId)->toBe('req_1')
        ->and($error->retryAfter)->toBe(60.0)
        ->and($error->getPrevious())->toBeInstanceOf(TransporterException::class));
    expect($state->closed)->toBeTrue()
        ->and($api->count())->toBe(1)
        ->and($api->sleeps)->toBe([]);
});

it('waits as Retry-After says when an error body fails to read', function () {
    $api = fakeApi([new Response(503, ['Retry-After' => '2'], failingBody()), data(CONTACT)]);
    expect($api->client->contacts->get('c1')->id)->toBe('c1')
        ->and($api->sleeps)->toHaveCount(1)
        ->and($api->sleeps[0])->toBeGreaterThanOrEqual(2.0);
});

it('retries an unkeyed write refused with 429 whose body fails to read', function () {
    // An endpoint the SDK doesn't know, without a key, is only retried when it didn't run.
    $api = fakeApi([new Response(429, ['Retry-After' => '1'], failingBody()), data(['ok' => true])]);
    expect($api->client->requestData('POST', '/v1/not-in-the-spec', ['a' => 1]))->toBe(['ok' => true])
        ->and($api->count())->toBe(2)
        ->and($api->request(1)->hasHeader('Idempotency-Key'))->toBeFalse();
});

it('still fails a successful response whose body fails to read', function () {
    fakeApi([new Response(200, [], failingBody())], ['maxRetries' => 0])->client->contacts->get('c1');
})->throws(TransporterException::class, 'Connection reset while reading');

it('refuses a body whose Content-Length is over the limit before reading it', function () {
    HttpTransporter::$maxResponseBody = 16;
    fakeApi([new Response(200, ['Content-Length' => '17'], str_repeat(' ', 17))])->client->contacts->get('c1');
})->throws(UnserializableResponse::class, 'with a body larger than');

it('still makes an error from a Content-Length over the limit', function () {
    HttpTransporter::$maxResponseBody = 16;
    $api = fakeApi([new Response(429, ['Content-Length' => '1000', 'Retry-After' => '60'], str_repeat(' ', 1000))]);
    expect(fn () => $api->client->contacts->get('c1'))
        ->toThrow(fn (ErrorException $error) => expect($error->status)->toBe(429)->and($error->retryAfter)->toBe(60.0));
});

/**
 * Starts the fixture server once, and returns its base URL. It stops when the tests end.
 */
function fixtureServer(): string
{
    static $url = null;
    if ($url !== null) {
        return $url;
    }
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $process = proc_open(
        [PHP_BINARY, '-S', $address, __DIR__ . '/Fixtures/server.php'],
        [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
        $pipes,
    );
    register_shutdown_function(fn () => proc_terminate($process));
    // Refused until the server is listening, which isn't a problem to report.
    set_error_handler(fn () => true);
    try {
        for ($tries = 0; $tries < 100 && stream_socket_client('tcp://' . $address) === false; $tries++) {
            usleep(50_000);
        }
    } finally {
        restore_error_handler();
    }

    return $url = "http://$address";
}

/**
 * @return array<string, array{0: Closure(): callable}>
 */
function realHandlers(): array
{
    return [
        'curl' => [fn () => new GuzzleHttp\Handler\CurlHandler()],
        'stream' => [fn () => new GuzzleHttp\Handler\StreamHandler()],
    ];
}

function realClient(?callable $handler = null): Kirora\Client
{
    return Kirora::client('test_key', [
        'baseUrl' => fixtureServer(),
        'httpClient' => new GuzzleHttp\Client(['handler' => GuzzleHttp\HandlerStack::create($handler ?? new GuzzleHttp\Handler\CurlHandler())]),
        'maxRetries' => 0,
        'timeout' => 10,
    ]);
}

it('stops a transfer that passes the limit', function (Closure $handler) {
    HttpTransporter::$maxResponseBody = 64 << 10;
    $started = microtime(true);
    // The body never ends, so only stopping the transfer gets here before the timeout.
    expect(fn () => realClient($handler())->contacts->get('endless'))
        ->toThrow(fn (UnserializableResponse $error) => expect($error->getMessage())->toContain('with a body larger than')
            ->and($error->requestId)->toBe('req_server'));
    expect(microtime(true) - $started)->toBeLessThan(5);
})->with(realHandlers());

it('stops an error transfer that passes the limit and keeps its status', function (Closure $handler) {
    HttpTransporter::$maxResponseBody = 64 << 10;
    expect(fn () => realClient($handler())->contacts->get('endless-error'))
        ->toThrow(fn (ErrorException $error) => expect($error->status)->toBe(429)
            ->and($error->retryAfter)->toBe(7.0)
            ->and($error->requestId)->toBe('req_server'));
})->with(realHandlers());

it('limits a compressed body by its decoded size', function () {
    HttpTransporter::$maxResponseBody = 64 << 10;
    realClient()->contacts->get('gzip');
})->throws(UnserializableResponse::class, 'with a body larger than');

it('refuses a Content-Length over the limit without reading the body', function (Closure $handler) {
    $started = microtime(true);
    expect(fn () => realClient($handler())->contacts->get('long'))
        ->toThrow(UnserializableResponse::class, 'with a body larger than 64 MiB');
    expect(microtime(true) - $started)->toBeLessThan(5);
})->with(realHandlers());

it('streams a raw response without the limit', function () {
    HttpTransporter::$maxResponseBody = 16;
    $response = realClient()->requestRaw('GET', '/v1/contacts/gzip');
    expect(strlen((string) $response->getBody()))->toBeGreaterThan(8 << 20);
});
