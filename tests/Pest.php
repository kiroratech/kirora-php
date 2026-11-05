<?php

declare(strict_types=1);

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Kirora\Client;
use Kirora\Transporters\HttpTransporter;
use Kirora\ValueObjects\Settings;
use Psr\Http\Message\RequestInterface;

/**
 * A fake API: the responses or exceptions to answer with, in order, and what was sent.
 */
final class FakeApi
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<float> */
    public array $sleeps = [];

    public MockHandler $handler;

    public Client $client;

    /**
     * @param  list<mixed>  $responses
     * @param  array<string, mixed>  $options
     */
    public function __construct(array $responses, array $options = [])
    {
        $this->handler = new MockHandler($responses);
        $stack = HandlerStack::create($this->handler);
        $history = [];
        $stack->push(Middleware::history($history));
        $this->history = &$history;
        $transporter = new HttpTransporter(
            new GuzzleClient(['handler' => $stack]),
            Settings::fromOptions('test_key', ['baseUrl' => 'https://api.test', ...$options]),
            function (float $seconds): void {
                $this->sleeps[] = $seconds;
            },
        );
        $this->client = new Client($transporter);
    }

    /** @var array<int, array{request: RequestInterface}> */
    private array $history;

    public function request(int $index = 0): RequestInterface
    {
        return $this->history[$index]['request'];
    }

    public function count(): int
    {
        return count($this->history);
    }

    /**
     * @return mixed The request's decoded JSON body.
     */
    public function body(int $index = 0): mixed
    {
        return json_decode((string) $this->request($index)->getBody(), true);
    }
}

/**
 * @param  list<mixed>  $responses
 * @param  array<string, mixed>  $options
 */
function fakeApi(array $responses, array $options = []): FakeApi
{
    return new FakeApi($responses, $options);
}

/**
 * @param  array<string, string>  $headers
 */
function json(int $status, mixed $body, array $headers = []): Response
{
    return new Response($status, ['Content-Type' => 'application/json', ...$headers], json_encode($body, JSON_THROW_ON_ERROR));
}

/**
 * @param  array<string, string>  $headers
 */
function data(mixed $data, int $status = 200, array $headers = []): Response
{
    return json($status, ['data' => $data], $headers);
}

/**
 * @param  list<mixed>  $items
 */
function page(array $items, ?string $nextCursor = null, array $meta = []): Response
{
    return json(200, ['data' => $items, 'meta' => ['pagination' => ['limit' => 50, 'nextCursor' => $nextCursor], ...$meta]]);
}

/**
 * @param  array<string, string>  $headers
 */
function apiError(int $status, string $code, string $message = 'It failed', array $headers = [], array $extra = []): Response
{
    return json($status, ['error' => ['code' => $code, 'message' => $message, ...$extra]], $headers);
}
