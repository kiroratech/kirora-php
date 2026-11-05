<?php

declare(strict_types=1);

namespace Kirora;

use Generator;
use Kirora\Contracts\Transporter;
use Kirora\Models\ApiCaller;
use Kirora\Service\ServiceFactory;
use Kirora\ValueObjects\Transporter\Payload;
use Psr\Http\Message\ResponseInterface;

/**
 * The Kirora API, with a property for each service. It holds your API key, so keep it on
 * your server. Create one with `Kirora::client()`, and reuse it.
 *
 * @property-read Service\Contacts $contacts The people you send to.
 * @property-read Service\Lists $lists Static lists of contacts, whose members you add and remove yourself.
 * @property-read Service\Campaigns $campaigns Messages sent once to an audience, by email, push or both.
 * @property-read Service\Transactional $transactional One-off messages to one recipient, such as receipts.
 * @property-read Service\Events $events What your contacts do, which starts journeys and feeds segments.
 * @property-read Service\Segments $segments Groups of contacts chosen by rules, kept up to date.
 * @property-read Service\Messages $messages The log of every message sent.
 * @property-read Service\Suppressions $suppressions Email addresses Kirora won't send to, such as after a bounce.
 * @property-read Service\Workspaces $workspaces Partner keys only: your tenants' workspaces.
 * @property-read Service\EmbedSessions $embedSessions Partner keys only: sessions for embedding Kirora's builders.
 */
class Client
{
    private readonly ServiceFactory $services;

    public function __construct(
        private readonly Transporter $transporter,
    ) {
        $this->services = new ServiceFactory($transporter);
    }

    public function __get(string $name): Service\Service
    {
        return $this->services->get($name);
    }

    public function __isset(string $name): bool
    {
        return $this->services->has($name);
    }

    /**
     * Returns the API key making the request and the workspace it acts in. Any valid key can
     * call it, so it makes a connection test: a revoked, expired or unknown key throws an
     * `ErrorException` with status 401.
     *
     * @param  array<string, mixed>  $options
     */
    public function me(array $options = []): ApiCaller
    {
        $response = $this->transporter->request(new Payload('GET', '/v1/me', [], null, $options));

        /** @var array<string, mixed> $data */
        $data = $response->data;

        return ApiCaller::from($data, $response->info);
    }

    /**
     * Returns a client that acts in the tenant workspace with this `externalId`. Partner keys
     * only. This client is unchanged.
     */
    public function forWorkspace(string $externalId): self
    {
        return new self($this->transporter->withWorkspace($externalId));
    }

    /**
     * Calls an endpoint that has no service method, with the same authentication, retries and
     * errors as the methods, and returns the whole decoded JSON body, envelope included, or
     * null for a response with no body. `$path` starts with `/v1` and may end in a query
     * string; encode each id in it with `rawurlencode()`. A non-null `$body` is sent as JSON.
     *
     * An endpoint this version of the SDK knows is retried as its method would be. On a newer
     * one, a POST or PATCH is retried after a network error or a 5xx only if you pass an
     * `idempotencyKey`, or `'repeatable' => true` to say it's safe to repeat.
     *
     * @param  array<string, mixed>  $options
     */
    public function request(string $method, string $path, mixed $body = null, array $options = []): mixed
    {
        return $this->transporter->request(Payload::fromPath($method, $path, $body, $options, Payload::FORMAT_JSON))->data;
    }

    /**
     * Calls an endpoint as `request()` does, and returns what's in its `data` envelope.
     *
     * @param  array<string, mixed>  $options
     * @return array<array-key, mixed>
     */
    public function requestData(string $method, string $path, mixed $body = null, array $options = []): array
    {
        /** @var array<array-key, mixed> */
        return $this->transporter->request(Payload::fromPath($method, $path, $body, $options, Payload::FORMAT_DATA))->data;
    }

    /**
     * Fetches one page of a list that has no service method, as `request()` does.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $options
     * @return Page<array<string, mixed>>
     */
    public function requestPage(string $path, array $query = [], array $options = []): Page
    {
        return Paginator::page($this->transporter, $path, $query, $options, null);
    }

    /**
     * Iterates over every item of a list that has no service method, fetching pages as it
     * goes, as the services' `all()` methods do.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $options
     * @return Generator<int, array<string, mixed>>
     */
    public function requestAll(string $path, array $query = [], array $options = []): Generator
    {
        return Paginator::all($this->transporter, $path, $query, $options, null);
    }

    /**
     * Calls an endpoint as `request()` does, but returns a successful response with its body
     * unread, such as a large export to stream to a file. The `timeout` limits connecting and
     * each read, not reading the whole body. An error response still throws an
     * `ErrorException`.
     *
     * @param  array<string, mixed>  $options
     */
    public function requestRaw(string $method, string $path, mixed $body = null, array $options = []): ResponseInterface
    {
        /** @var ResponseInterface */
        return $this->transporter->request(Payload::fromPath($method, $path, $body, $options, Payload::FORMAT_RAW))->data;
    }
}
