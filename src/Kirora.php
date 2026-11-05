<?php

declare(strict_types=1);

use GuzzleHttp\Client as GuzzleClient;
use Kirora\Client;
use Kirora\Transporters\HttpTransporter;
use Kirora\ValueObjects\Settings;
use Psr\Http\Client\ClientInterface;

final class Kirora
{
    /**
     * The version of this SDK, sent in the `User-Agent` header.
     */
    public const VERSION = '0.1.0';

    /**
     * Creates a client that authenticates with `$apiKey`. A null or empty key is read from the
     * `KIRORA_API_KEY` environment variable instead. Spaces and quotes around the key are
     * ignored, as from a pasted `.env` value.
     *
     * Options:
     *
     * - `baseUrl`: the API host, without `/v1`. Defaults to `KIRORA_BASE_URL`, then
     *   `https://api.kirora.io`.
     * - `timeout`: how long each attempt may take, in seconds. Defaults to 30.
     * - `maxRetries`: attempts after the first, from 0 to 10. Defaults to 3.
     * - `maxRetryDelay`: the longest single wait between attempts, in seconds. Defaults to
     *   30. When the API asks for a longer wait with `Retry-After`, the call throws instead.
     * - `workspace`: partner keys only, the `externalId` of the tenant workspace to act in.
     * - `headers`: headers to send with every request. Those the SDK sets itself, such as
     *   `Authorization`, take precedence.
     * - `appInfo`: `['name' => ..., 'version' => ...]`, your app, added to the `User-Agent`.
     * - `httpClient`: a PSR-18 client to send requests with. Defaults to a new Guzzle client.
     *
     * @param  array{baseUrl?: string, timeout?: int|float, maxRetries?: int, maxRetryDelay?: int|float, workspace?: string, headers?: array<string, string>, appInfo?: array{name: string, version?: string}, httpClient?: ClientInterface}  $options
     *
     * @throws InvalidArgumentException if there's no API key, or an option is invalid.
     */
    public static function client(?string $apiKey = null, array $options = []): Client
    {
        $httpClient = $options['httpClient'] ?? new GuzzleClient();
        if (! $httpClient instanceof ClientInterface) {
            throw new InvalidArgumentException('Kirora: httpClient must be a PSR-18 ' . ClientInterface::class);
        }
        unset($options['httpClient']);

        return new Client(new HttpTransporter($httpClient, Settings::fromOptions($apiKey, $options)));
    }
}
