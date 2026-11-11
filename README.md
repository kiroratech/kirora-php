# Kirora PHP SDK

Use the Kirora API from PHP to manage contacts, track events, build segments, and send
campaigns and transactional messages.

[PHP guide](https://kirora.io/docs/sdks/php) ·
[API reference](https://kirora.io/docs/api/reference) ·
[Usage guide](docs/usage.md) · [Changelog](CHANGELOG.md)

- Models generated from Kirora's OpenAPI specification.
- Automatic pagination and retries, with idempotency keys for supported operations.
- Webhook verification, user token signing, and partner workspace support.
- Works with any framework, or none. Sends requests with Guzzle by default, or with any
  PSR-18 client.

## Contents

- [Installation](#installation)
- [Quickstart](#quickstart)
- [Usage](#usage)
- [Pagination](#pagination)
- [Client configuration](#client-configuration)
- [Errors](#errors)
- [Retries and idempotency](#retries)
- [More guides](#more-guides)
- [Troubleshooting](#troubleshooting)
- [Compatibility](#compatibility), [help](#help), and [development](#development)

## Installation

Requires PHP 8.1 or later.

```sh
composer require kirora/kirora-php
```

Create an API key under **Developer → Server keys** in the Kirora dashboard, with the
scopes your integration needs. Set it in your server's environment:

```sh
export KIRORA_API_KEY="your_api_key"
```

Keep the key on your server and out of source control. The SDK checks `getenv()` first,
then falls back to `$_ENV` and `$_SERVER` when the process variable is missing or empty.
It does not load `.env` files automatically. If your framework loads configuration
another way, pass the key to `Kirora::client($apiKey)`. For browser
and mobile apps, use
[server-issued user tokens](docs/usage.md#authenticate-browser-and-mobile-sdk-users).

## Quickstart

Save this as `quickstart.php` beside your project's `vendor/` directory. It creates or
updates a contact; replace the external ID and email with your own test values.

```php
<?php

require __DIR__ . '/vendor/autoload.php';

$kirora = Kirora::client(); // Reads KIRORA_API_KEY.

$contact = $kirora->contacts->identify([
    'externalId' => 'php-quickstart-user',
    'email' => 'ada@example.com',
]);

echo "Contact saved: {$contact->id}\n";
```

Run it with the environment variable set:

```sh
php quickstart.php
```

A successful request prints `Contact saved: <contact ID>`. To check your credentials
without creating data, replace the contact operation with:

```php
$caller = $kirora->me();
echo $caller->workspace['name'], "\n";
```

In namespaced application code, use `\Kirora::client()` or import the global `Kirora`
class. The factory creates a `Kirora\Client`; no framework-specific facade is required.

## Usage

The snippets below use `$kirora` from the quickstart. Create one client and reuse it
across the operations in your request or worker.

### Contacts

`identify()` creates or updates a contact, matching by external ID, then email, then
phone. Look a contact up by Kirora's ID, or pass `idType` to use your own:

```php
$contact = $kirora->contacts->get('ada@example.com', ['idType' => 'email']);
echo $contact->id;
```

`idType` can be `'email'` or `'externalId'`; leave it out for a Kirora ID. It is a query
parameter, while `timeout` and `maxRetries` are call options. For methods that take both,
the query array comes first:

```php
$contact = $kirora->contacts->get(
    'php-quickstart-user',
    ['idType' => 'externalId'],
    ['timeout' => 5, 'maxRetries' => 0],
);
```

Leave a field out of an update to keep its value. Set a **nullable** field to `null` to
clear it; `null` is sent to the API and does not mean “leave unchanged.”

A contact's lists, segments, topic preferences, and push installations have their own
services: `contacts->lists`, `contacts->segments`, `contacts->topics`, and
`contacts->installations`. For example, list the quickstart contact's memberships:

```php
foreach ($kirora->contacts->lists->all($contact->id) as $membership) {
    echo $membership->id, "\n";
}
```

These methods also accept an `idType` query. Topics return an array from `list()`;
lists, segments, and installations support paginated `list()` and `all()`. See
[contact services](docs/usage.md#contacts).

### Events

Track an event for a contact that already exists:

```php
$kirora->events->track([
    'externalId' => 'php-quickstart-user',
    'name' => 'Order Completed',
    'properties' => ['orderId' => '1234', 'total' => 49.99, 'currency' => 'GBP'],
], ['idempotencyKey' => 'order-1234-completed']);
```

Derive the idempotency key from the operation in your application, and reuse it when
retrying that same operation with the same body.

### Transactional messages

Complete your workspace's email sending setup and configure an email template first.
Replace `order-receipt` with your template's alias or ID and use a recipient you control.

```php
$sent = $kirora->transactional->send([
    'to' => ['email' => 'customer@example.com'],
    'data' => ['orderId' => '1234'],
    'email' => ['template' => 'order-receipt'],
], ['idempotencyKey' => 'receipt-1234']);

echo count($sent->messages), " message(s) queued\n";
```

A successful call can leave a requested channel unsent. Inspect `skippedChannels`,
for example when a contact has no push device:

```php
foreach ($sent->skippedChannels as $skipped) {
    echo $skipped->channel, ': ', $skipped->code, "\n";
}
```

Likewise, a `cc` or `bcc` address can get no copy, for example when it's suppressed.
Inspect `skippedCopies`:

```php
foreach ($sent->skippedCopies as $skipped) {
    echo $skipped->kind, ' ', $skipped->email, ': ', $skipped->code, "\n";
}
```

`contacts->identifyBatch()`, `events->trackBatch()` and `transactional->sendBatch()` accept
up to 100 entries. A successful call can contain failed entries: inspect each entry in
`$batch->results` for an `error`. For event and transactional batches, keys belong to
individual entries. Set each entry's `idempotencyKey` when retrying from your application;
see [batch requests](docs/usage.md#batch-requests).

### Services

| Service         | Use it to                                                                                                       |
| --------------- | --------------------------------------------------------------------------------------------------------------- |
| `contacts`      | Identify, update, merge, export, and delete contacts; manage preferences and push devices; list their segments. |
| `events`        | Track individual events or batches.                                                                             |
| `transactional` | Send messages individually or in batches; cancel scheduled messages.                                            |
| `campaigns`     | Create campaigns, check eligibility, send, and schedule.                                                        |
| `segments`      | Create and preview segments; list their contacts and see their size over time.                                  |
| `lists`         | Create lists and manage membership.                                                                             |
| `messages`      | Inspect sent messages.                                                                                          |
| `suppressions`  | Manage suppressed email addresses.                                                                              |
| `workspaces`    | Manage tenant workspaces with a partner key.                                                                    |
| `embedSessions` | Create sessions for embedded Kirora builders.                                                                   |

Requests take arrays, as the [API reference](https://kirora.io/docs/api/reference)
describes them. Responses are models from `Kirora\Models`: read fields as properties,
`$contact->email`, or as array keys, `$contact['email']`. Your editor completes them from
each model's documented fields. Models are read-only; use `toArray()` or `toJson()` to
serialize them. Unknown response fields are preserved. See [models](docs/usage.md#models).

## Pagination

Use `all()` to iterate every matching item, fetching additional pages as needed:

```php
foreach ($kirora->contacts->all(['query' => 'ada']) as $contact) {
    echo $contact->email, "\n";
}
```

`all()` makes its first request when iteration starts. Use `break` to stop fetching.
Use `list()` for one page; iterating that page does **not** fetch subsequent pages:

```php
$page = $kirora->contacts->list(['query' => 'ada', 'limit' => 50]);
foreach ($page as $contact) {
    echo $contact->id, "\n";
}
if ($page->hasMore()) {
    $next = $kirora->contacts->list([
        'query' => 'ada',
        'limit' => 50,
        'cursor' => $page->nextCursor(),
    ]);
}
```

Keep the same filters when following a cursor. `nextCursor()` returns `null` on the last
page; an empty page alone does not mean pagination is finished. See [pagination details](docs/usage.md#pagination).

## Client configuration

Create one client and reuse it. Pass an API key to `Kirora::client()`, or nothing to read
`KIRORA_API_KEY`:

```php
$kirora = Kirora::client(null, [
    'timeout' => 10,
    'maxRetries' => 2,
]);
```

| Option          | Default                                      | Purpose                                                                   |
| --------------- | -------------------------------------------- | ------------------------------------------------------------------------- |
| `timeout`       | 30 seconds                                   | Seconds per attempt with Guzzle, including reading the response.          |
| `maxRetries`    | 3                                            | Allow 0–10 retries after the first attempt.                               |
| `maxRetryDelay` | 30 seconds                                   | Limit the wait between attempts; a longer `Retry-After` stops retries.    |
| `baseUrl`       | `KIRORA_BASE_URL` or `https://api.kirora.io` | Set the API host, without `/v1`.                                          |
| `httpClient`    | A new Guzzle client                          | Use your own PSR-18 client, such as one with a proxy.                     |
| `workspace`     | None                                         | Scope a partner key to a tenant workspace.                                |
| `headers`       | None                                         | Add headers to every request.                                             |
| `appInfo`       | None                                         | Add your app to the `User-Agent`, as `['name' => ..., 'version' => ...]`. |

Every method takes call options as its last argument. `timeout`, `maxRetries`,
`maxRetryDelay`, `workspace` and `headers` override the client's setting for that call,
and `idempotencyKey` is per call only. For methods with a query argument, pass `[]` to
skip the query before supplying call options. See [configuration details](docs/usage.md#configuration).

Retries and backoff can make a call take longer than `timeout`. A custom PSR-18 client
that does not implement Guzzle's client interface must have its timeouts configured on
that client; the SDK cannot enforce its `timeout` option there. `requestRaw()` returns an
unread response body, so its timeout covers connecting and individual reads rather than
consuming the whole stream.

## Errors

API errors throw `Kirora\Exceptions\ErrorException`:

```php
use Kirora\Enums\ErrorCode;
use Kirora\Exceptions\ErrorException;
use Kirora\Exceptions\TransporterException;

try {
    $contact = $kirora->contacts->get('php-quickstart-user', ['idType' => 'externalId']);
} catch (TransporterException $error) {
    // Automatic retries have finished, or the operation could not be safely retried.
    error_log($error->getMessage());
    throw $error;
} catch (ErrorException $error) {
    if ($error->is(ErrorCode::NotFound)) {
        return null; // Handle the missing contact in your application.
    }
    error_log("Kirora error: {$error->errorCode} status={$error->status} request_id={$error->requestId}");
    throw $error;
}
```

`ErrorException` includes the API code, HTTP status, request ID, field errors, and
available rate-limit details. Include the request ID when reporting a failure. Network
failures throw `TransporterException` (or `TimeoutException`, a subclass); responses the
SDK can't use throw `UnserializableResponse`. All of them implement
`Kirora\Exceptions\KiroraException`.

Invalid local arguments throw PHP's `InvalidArgumentException` before a request is
sent; that exception does not implement `KiroraException`.

See [error handling](docs/usage.md#errors) and the
[API error codes](https://kirora.io/docs/api/reference/errors).

## Retries

The SDK allows up to **3 retries** by default, with exponential backoff and jitter:

- Rate limits (429) and failures that never established a connection can be retried.
- Other network failures and 5xx responses are retried only when repeating the operation
  is safe.
- `IDEMPOTENCY_KEY_IN_USE` (409) can be retried when the call has a key.
- Other 4xx responses are not retried.

Pass `'maxRetries' => 0` to disable retries. See the [retry guide](docs/usage.md#retries-and-idempotency)
for operation-specific rules.

### Idempotency keys

For supported operations, the SDK generates an idempotency key per call and reuses it for
its own retries. Separate calls get separate keys. To deduplicate retries from your
application, pass a stable `idempotencyKey` to each call for the same operation and body,
as in the event and transactional examples above.

## More guides

The [usage guide](docs/usage.md) covers each of these in detail.

### Webhooks

Verify the raw request body with `Kirora\Webhook::verify()` before decoding it:

```php
use Kirora\Webhook;

$secret = getenv('KIRORA_WEBHOOK_SECRET');
if ($secret === false || $secret === '') {
    http_response_code(500); // Configure the endpoint's signing secret on the server.
    exit;
}
$rawBody = file_get_contents('php://input');
if ($rawBody === false) {
    http_response_code(400);
    exit;
}
$webhook = Webhook::verify($secret, $rawBody, getallheaders());
if ($webhook === null) {
    http_response_code(401);
    exit;
}
// Handle $webhook->payload; deduplicate on $webhook->payload['eventId'].
```

Webhooks can arrive more than once; deduplicate on the event ID, and record an event as
handled only once its work has succeeded, so a failed delivery is handled again on retry.
See [verifying webhooks](docs/usage.md#verify-webhooks).

### Authenticate browser and mobile SDK users

Create user tokens on your server with `Kirora\UserToken::create()`, using your SDK token
secret and the authenticated user's ID from your server session, never an ID supplied
by the browser. The SDK token secret is separate from your API key. See
[user token signing](docs/usage.md#authenticate-browser-and-mobile-sdk-users).

### Partner workspaces

Use `$kirora->forWorkspace($externalId)` with a partner key to scope calls to a tenant.
See [partner workspaces](docs/usage.md#partner-workspaces).

### Advanced requests

Use `request()` for endpoints without a service method, `requestAll()` for paginated
results, and `requestRaw()` to stream a response. Models and pages expose response
metadata through `lastResponse()`: request ID, rate limit and idempotency replay. See
[advanced requests](docs/usage.md#call-endpoints-without-service-methods).

## Troubleshooting

| Problem                                | Check                                                                                                                                 |
| -------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| API key is missing                     | Set `KIRORA_API_KEY` in the PHP process environment, or pass the key explicitly. A `.env` file alone is not loaded by the SDK.        |
| API returns `401`                      | Check whether the key is valid, expired or revoked.                                                                                   |
| API returns `403`                      | Check the endpoint's required scopes, accepted key kinds and partner workspace requirements.                                          |
| A timeout override has no effect       | Put it in the call-options array, after any query array. Configure timeouts directly on non-Guzzle PSR-18 clients.                    |
| Only one page is returned              | Use `all()` for automatic pagination; `list()` returns one page.                                                                      |
| A batch succeeds but some entries fail | Inspect each entry in `results` for an `error`.                                                                                       |
| Webhook verification fails             | Use the endpoint's signing secret and the unchanged raw body. See [verification diagnostics](docs/usage.md#verification-diagnostics). |

## Compatibility

- **PHP:** 8.1 or later.
- **API:** targets `/v1`. Unknown enum values and error codes are kept as strings; use
  `ErrorCode::isKnown()` and `WebhookEvent::isKnown()` to check whether your SDK version
  recognizes a value. Fields this SDK has no model property for are kept too, so nothing
  the API sends is lost.
- **Releases:** before 1.0, minor releases may include breaking changes. Review the
  [changelog](CHANGELOG.md) when upgrading.

## Help

For SDK bugs or feature requests,
[open an issue](https://github.com/kiroratech/kirora-php/issues). Include your SDK and
PHP versions, a minimal reproduction, and the request ID when available. Remove API keys,
tokens, and personal data before sharing logs.

For integration help, email [support@kirora.io](mailto:support@kirora.io) or start with
the [PHP guide](https://kirora.io/docs/sdks/php), the
[API reference](https://kirora.io/docs/api/reference), or the
[usage guide](docs/usage.md).

## Development

```sh
composer install
composer test
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for OpenAPI code generation and the full set of
checks. The test suite requires PHP 8.2 or later; using the SDK requires PHP 8.1 or later.

## License

[MIT](LICENSE)
