<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use Kirora\Client;

beforeEach(function () {
    putenv('KIRORA_API_KEY');
    putenv('KIRORA_BASE_URL');
    unset($_ENV['KIRORA_API_KEY'], $_SERVER['KIRORA_API_KEY'], $_ENV['KIRORA_BASE_URL'], $_SERVER['KIRORA_BASE_URL']);
});

it('creates a client', function () {
    expect(Kirora::client('key_123'))->toBeInstanceOf(Client::class);
});

it('reads the API key from KIRORA_API_KEY', function () {
    putenv('KIRORA_API_KEY=key_from_env');
    expect(Kirora::client())->toBeInstanceOf(Client::class);
});

it('refuses a missing key', function (?string $key) {
    Kirora::client($key);
})->with([null, '', '  ', '""', 'undefined', 'null'])->throws(InvalidArgumentException::class, 'an API key is required');

it('ignores spaces and quotes around the key', function () {
    $history = [];
    $stack = GuzzleHttp\HandlerStack::create(new GuzzleHttp\Handler\MockHandler([data([])]));
    $stack->push(GuzzleHttp\Middleware::history($history));
    $client = Kirora::client(' "key_123" ', ['httpClient' => new GuzzleHttp\Client(['handler' => $stack])]);
    $client->me();
    expect($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer key_123');
});

it('validates the base URL', function (mixed $url) {
    Kirora::client('key', ['baseUrl' => $url]);
})->with(['api.kirora.io', 'ftp://api.kirora.io', 'https://api.kirora.io?x=1', 'https://api.kirora.io#a', 'https://', 5])
    ->throws(InvalidArgumentException::class, 'baseUrl must be an http or https URL');

it('uses KIRORA_BASE_URL unless baseUrl is passed', function () {
    putenv('KIRORA_BASE_URL=https://proxy.test/kirora/');
    $history = [];
    $stack = GuzzleHttp\HandlerStack::create(new GuzzleHttp\Handler\MockHandler([data([]), data([])]));
    $stack->push(GuzzleHttp\Middleware::history($history));
    $http = new GuzzleHttp\Client(['handler' => $stack]);

    Kirora::client('key', ['httpClient' => $http])->me();
    Kirora::client('key', ['httpClient' => $http, 'baseUrl' => 'https://other.test'])->me();

    expect((string) $history[0]['request']->getUri())->toBe('https://proxy.test/kirora/v1/me')
        ->and((string) $history[1]['request']->getUri())->toBe('https://other.test/v1/me');
});

it('says which KIRORA_BASE_URL is invalid', function () {
    putenv('KIRORA_BASE_URL=nope');
    Kirora::client('key');
})->throws(InvalidArgumentException::class, 'KIRORA_BASE_URL must be');

it('refuses unknown options', function () {
    Kirora::client('key', ['timeoutMs' => 5]);
})->throws(InvalidArgumentException::class, 'doesn\'t take the option "timeoutMs"');

it('refuses an Idempotency-Key header on the client', function () {
    Kirora::client('key', ['headers' => ['Idempotency-Key' => 'k']]);
})->throws(InvalidArgumentException::class, 'must be passed to a call');

it('validates the numeric options', function (array $options) {
    Kirora::client('key', $options);
})->with([
    [['timeout' => 0]],
    [['timeout' => -1]],
    [['maxRetries' => 11]],
    [['maxRetries' => -1]],
    [['maxRetries' => 1.5]],
    [['maxRetryDelay' => -1]],
])->throws(InvalidArgumentException::class);

it('refuses an empty workspace', function () {
    Kirora::client('key', ['workspace' => '']);
})->throws(InvalidArgumentException::class, 'workspace must be');

it('refuses a client that isn\'t PSR-18', function () {
    Kirora::client('key', ['httpClient' => new stdClass()]);
})->throws(InvalidArgumentException::class, 'httpClient must be a PSR-18');

it('adds the app to the User-Agent', function () {
    $api = fakeApi([data([])], ['appInfo' => ['name' => 'my-app', 'version' => '1.2.3']]);
    $api->client->me();
    expect($api->request()->getHeaderLine('User-Agent'))
        ->toBe('kirora-php/' . Kirora::VERSION . ' php/' . PHP_VERSION . ' my-app/1.2.3');
});

it('refuses app info with spaces or slashes', function () {
    Kirora::client('key', ['appInfo' => ['name' => 'my app']]);
})->throws(InvalidArgumentException::class, 'appInfo');

it('names its services', function () {
    $client = Kirora::client('key');
    expect($client->contacts)->toBeInstanceOf(Kirora\Service\Contacts::class)
        ->and($client->contacts)->toBe($client->contacts)
        ->and(isset($client->embedSessions))->toBeTrue()
        ->and(isset($client->nope))->toBeFalse();
    $client->nope;
})->throws(InvalidArgumentException::class, 'there\'s no service "nope"');

it('has a semantic version', function () {
    expect(Kirora::VERSION)->toMatch('/^\d+\.\d+\.\d+$/');
});

it('acts in a workspace with forWorkspace', function () {
    $api = fakeApi([data([]), data([])]);
    $tenant = $api->client->forWorkspace('tenant-42');
    $tenant->me();
    $api->client->me();
    expect($api->request(0)->getHeaderLine('Kirora-Workspace'))->toBe('tenant-42')
        ->and($api->request(1)->hasHeader('Kirora-Workspace'))->toBeFalse();
});

it('refuses forWorkspace with an empty id', function () {
    Kirora::client('key')->forWorkspace('');
})->throws(InvalidArgumentException::class);

it('returns a raw response unread', function () {
    $api = fakeApi([new Response(200, ['Content-Type' => 'text/csv'], "a,b\n1,2\n")]);
    $response = $api->client->requestRaw('GET', '/v1/messages/exports/e1/download');
    expect((string) $response->getBody())->toBe("a,b\n1,2\n");
});
