# PHP SDK usage guide

The examples use `$kirora` from the [quickstart](../README.md#quickstart). Values such as
`$campaignId` and `$messageId` come from your application.

## Contents

- [Usage](#usage): contacts, events, transactional messages, campaigns, segments,
  lists, messages and suppressions
- [Models](#models)
- [Optional fields](#optional-fields)
- [Pagination](#pagination)
- [Errors](#errors)
- [Configuration](#configuration)
- [Retries and idempotency](#retries)
- [Verify webhooks](#verify-webhooks)
- [Advanced usage](#advanced-usage): batches, response details, user tokens, partner
  workspaces, endpoints without service methods
- [Testing your code](#testing-your-code)

## Usage

### Check your connection

`me()` returns the API key making the request and the workspace it acts in. Any valid
key can call it, so it makes a connection test: a revoked, expired or unknown key throws
an `ErrorException` with status 401.

```php
$caller = $kirora->me();
echo $caller->workspace['name'];
```

### Contacts

```php
$kirora->contacts->update($contact->id, ['firstName' => 'Ada']);

$ada = $kirora->contacts->get('ada@example.com', ['idType' => 'email']);
```

Look up contacts by their Kirora ID, or pass `'idType' => 'email'` or
`'idType' => 'externalId'` to use your own identifiers.

Contacts also have:

- `identifyBatch()` and `deleteBatch()`, for up to 100 contacts at once
- `delete()`, `merge()` and `export()`, for removing, merging and exporting one contact

What belongs to a contact is reached through its properties, each method taking the
contact's id first:

- `contacts->lists`: `list()` and `all()`, the lists it's in
- `contacts->segments`: `list()` and `all()`, the segments it's in
- `contacts->topics`: `list()` and `set()`, for a preference centre
- `contacts->installations`: `list()`, `all()`, `register()` and `delete()`, for push
  devices

```php
$kirora->contacts->topics->set($contactId, $topicId, ['subscribed' => false]);

foreach ($kirora->contacts->lists->all($contactId) as $membership) {
    echo $membership->name, "\n";
}
```

Each method that takes a contact id also takes `['idType' => ...]`, as `get()` does. That
query comes before the call options, so pass `[]` to skip it:
`$kirora->contacts->get($contactId, [], ['timeout' => 5])`.

### Events

Track an event for an existing contact:

```php
$kirora->events->track([
    'externalId' => 'example-user-123',
    'name' => 'Order Completed',
    'properties' => ['total' => 49.99, 'currency' => 'GBP'],
], ['idempotencyKey' => 'order-123-completed']);
```

Use a stable event ID for the idempotency key. See [Retries and idempotency](#retries)
and [batch requests](#batch-requests).

### Transactional messages

Replace `order-receipt` with the alias or ID of an existing Kirora email template and use
a recipient you control.

```php
$sent = $kirora->transactional->send([
    'to' => ['email' => 'customer@example.com'],
    'data' => ['orderId' => 'order-123'],
    'email' => ['template' => 'order-receipt'],
], ['idempotencyKey' => 'receipt-order-123']);
```

Check delivery status with `$kirora->messages`. Reuse the idempotency key only for the
same receipt and request body.

`transactional->cancel($messageId)` cancels a message scheduled with `sendAt`.

### Campaigns

Use the ID of an existing campaign as `$campaignId`. Check what would block a send,
without sending:

```php
$eligibility = $kirora->campaigns->eligibility($campaignId);
```

Send it now:

```php
$kirora->campaigns->send($campaignId);
```

Or schedule it instead, here for an hour from now:

```php
$kirora->campaigns->schedule($campaignId, [
    'type' => 'fixed',
    'sendAt' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
]);
```

A campaign is only ever sent once, so `send()` and `schedule()` are alternatives:
scheduling works on a draft, or reschedules one that hasn't started sending.

`campaigns->trigger($campaignId, $params)` sends to the contacts you give it, each with
its own `data`, instead of the campaign's saved audience.

### Segments

Segments are audiences described by rules, whose membership changes as contacts and their
events change. Check who some rules would match before saving them:

```php
$preview = $kirora->segments->preview(['rules' => $rules]);
$segment = $kirora->segments->create(['name' => 'Repeat buyers', 'rules' => $rules]);

foreach ($kirora->segments->allContacts($segment->id) as $contact) {
    echo $contact->email, "\n";
}
```

Kirora counts a saved segment's members in the background, about every 15 minutes, so
`memberCount`, `listContacts()` and `contacts->segments->list()` can be up to that far
behind. `sizeHistory()` and `listMembershipChanges()` show how it changed day by day, and
`usage()` what uses it: deleting a segment is refused with `IN_USE` while an unsent
campaign, an active or paused journey, or another segment uses it.

### Lists

```php
$list = $kirora->lists->create(['name' => 'VIPs']);
$kirora->lists->addContacts($list->id, ['emails' => ['ada@example.com']]);
```

`addContacts()` and `deleteContacts()` take `contactIds`, `externalIds` or `emails`.
Existing members, and non-members, are skipped.

### Messages and suppressions

```php
$message = $kirora->messages->get($messageId);
echo $message->status;

$kirora->suppressions->add(['emails' => ['bounced@example.com']]);
```

## Models

Responses are models from `Kirora\Models`, generated from the API spec. Read a field as a
property or an array key; a field the response doesn't have reads as null:

```php
$contact = $kirora->contacts->get($contactId);

$contact->email;            // or $contact['email']
$contact->rfm?->group;      // nested objects are models too
$contact->attributes['plan'];
$contact->toArray();        // everything, as arrays
json_encode($contact);
```

Each model's fields are documented on its class, so your editor completes them. Fields
this version of the SDK doesn't know are kept, and read the same way. Models are
read-only. A schema whose name PHP reserves gets `Model` added: `List` is `ListModel`.

## Optional fields

Requests take arrays, as the [API reference](https://kirora.io/docs/api/reference)
describes them. For updates, leave a field out to keep its value, and set a nullable
field to `null` to clear it:

```php
$kirora->contacts->update($contact->id, [
    'firstName' => 'Ada',
    'lastName' => null,
    // phone is left out and keeps its current value.
]);
```

An empty PHP array encodes as JSON `[]`. When decoding JSON into associative arrays,
both `{}` and `[]` become an empty PHP array. Where the API spec says a field can only be an object, such as an event's `properties` or a
contact's `attributes`, the SDK sends an empty array as `{}`, so a model's `toArray()` can
be sent back as it is. Anywhere else, an empty array is sent as `[]`; pass
`new stdClass()` there for an empty object.

## Pagination

`list()` fetches one page, and `all()` iterates over every item, fetching pages as it
goes:

```php
// Every matching contact
foreach ($kirora->contacts->all(['query' => 'ada']) as $contact) {
    echo $contact->email, "\n";
}

// One page, with the total count on the first
$page = $kirora->contacts->list(['query' => 'ada']);
echo $page->meta['count'], ' ', count($page);
foreach ($page as $contact) {
    // this page's contacts
}

// The next page: pass the cursor back with the same filters. It's null on the last page.
if ($page->hasMore()) {
    $next = $kirora->contacts->list(['query' => 'ada', 'cursor' => $page->nextCursor()]);
}
```

`all()` fetches nothing until you iterate, and nothing more once you `break`. It throws an
`UnserializableResponse` if the API returns a cursor it already returned, since the list
would never end. `contacts->topics->list()` returns an array instead of pages. See
[API pagination](https://kirora.io/docs/api/reference/pagination) for cursor details.

## Errors

API errors throw `Kirora\Exceptions\ErrorException`. Branch on `errorCode`, or `is()`:

```php
use Kirora\Enums\ErrorCode;
use Kirora\Exceptions\ErrorException;

try {
    $kirora->contacts->get($userId, ['idType' => 'externalId']);
} catch (ErrorException $error) {
    if ($error->is(ErrorCode::NotFound)) {
        return null; // never identified
    }
    $logger->error('Kirora request failed', [
        'code' => $error->errorCode,
        'requestId' => $error->requestId,
    ]);
    throw $error;
}
```

Quote `requestId` to Kirora support. Every code is listed in
[Error codes](https://kirora.io/docs/api/reference/errors), and as the cases of
`Kirora\Enums\ErrorCode`. A code added after your version of the package still comes
through as a string; `$error->isKnownCode()` tells you which. An error response without a
code, such as from a proxy, gets one from its status: `NOT_FOUND` for a 404, say.

`ErrorException` also has `status` (also its `getCode()`), `errorMessage`, `details`,
`retryAfter` (seconds), `rateLimit` and `response`. On `VALIDATION_ERROR` and
`DRAFT_INCOMPLETE`, `errors` lists each field the request got wrong, with a message to
show beside it:

```php
if ($error->is(ErrorCode::ValidationError)) {
    foreach ($error->errors as $fieldError) {
        $form->addError($fieldError['field'], $fieldError['message']);
    }
}
```

Other request failures also implement `Kirora\Exceptions\KiroraException`:

- `TransporterException`: no complete response, such as a network error, or the
  connection dropped while reading the body. Its cause is `getPrevious()`.
- `TimeoutException`: an attempt took longer than `timeout`. A subclass of
  `TransporterException`.
- `UnserializableResponse`: a response the SDK can't use, such as HTML from a proxy, a
  redirect, a page with an item that isn't an object, or a body over 64 MiB. It has
  `status` and `requestId`.

An error response is always an `ErrorException`, even when its body is too large or drops
part way: it's built from the status and headers, so it's retried as they say. With the
default Guzzle client the SDK stops downloading a body once it passes 64 MiB; a custom
PSR-18 client has received the whole body before the SDK sees it.

Invalid local arguments throw PHP's `InvalidArgumentException`, which does not implement
`KiroraException`. Examples include an empty ID, an empty `workspace` or an unknown
option. Nothing is sent.

## Configuration

Set client defaults when you create the client:

```php
$kirora = Kirora::client(null, [
    'timeout' => 30,
    'maxRetries' => 3,
    'maxRetryDelay' => 30,
]);
```

| Option           | Default                                         | Description                                                                             |
| ---------------- | ----------------------------------------------- | --------------------------------------------------------------------------------------- |
| (first argument) | `KIRORA_API_KEY`                                | Your API key. `Kirora::client()` throws if there's none.                                |
| `baseUrl`        | `KIRORA_BASE_URL`, then `https://api.kirora.io` | The host, without `/v1`. For tests and mocks.                                           |
| `timeout`        | `30`                                            | How long each attempt may take, in seconds, including reading the response.             |
| `maxRetries`     | `3`                                             | Attempts after the first, from 0 to 10.                                                 |
| `maxRetryDelay`  | `30`                                            | The longest single wait, in seconds; a longer `Retry-After` stops retries.              |
| `httpClient`     | a new Guzzle client                             | A PSR-18 client, such as a Guzzle client with a proxy or middleware.                    |
| `workspace`      | none                                            | Partner keys only: the tenant to act in. See [partner workspaces](#partner-workspaces). |
| `appInfo`        | none                                            | `['name' => ..., 'version' => ...]`, added to the `User-Agent` header.                  |
| `headers`        | none                                            | Headers to send with every request. The SDK's own headers take precedence.              |

`timeout` limits each attempt when the client is Guzzle, which it is by default. Another
PSR-18 client is used as it is, so set its timeout on it. PHP has no way to cancel a call
from outside, so for an overall limit, lower `maxRetries` or `maxRetryDelay`.

An unknown option throws an `InvalidArgumentException`, so a typo can't be ignored.

### Per-call options

Every method takes an optional last argument with:

- `idempotencyKey`, to make your own retries safe (see [Idempotency](#idempotency-keys))
- `workspace`, to act in for this call only
- `timeout`, `maxRetries` and `maxRetryDelay`, overriding the client's settings
- `headers`, over the client's `headers` for this call

```php
$kirora->lists->get($listId, ['timeout' => 5, 'maxRetries' => 0]);
```

<a id="retries"></a>

## Retries and idempotency

The SDK retries eligible failures up to three times with backoff. For endpoints that
accept idempotency keys, it generates a key and reuses it across its own retries.

### Retry behavior

Failed requests are retried with exponential backoff and jitter, respecting
`Retry-After`:

- **Rate limits (429) and connections never made** (the host wasn't found or refused the
  connection) are always retried, since the server did nothing.
- **Network errors, timeouts and 5xx** are retried only when it can't cause a duplicate:
  reads, requests with an idempotency key, and operations the API says are safe to repeat,
  such as `contacts->identify()` or `lists->update()`. A `contacts->update()` that changes
  the email or `externalId` it looks the contact up by isn't retried.
- **`IDEMPOTENCY_KEY_IN_USE`** is retried, since an earlier request with the same key is
  still running and a retry gets its response.
- **Other 4xx errors** are never retried. A delete retried after an attempt that may have
  done it treats a 404 as success.

A retry sends the same body and idempotency key as the first attempt. Which operations are
safe to repeat comes from the API spec, as `x-retry` on each operation.

### Idempotency keys

Pass your own `idempotencyKey` when your application may retry an operation. Reuse it only
for the same operation and request body, including when a call fails without a response:
the API refuses a key reused with a different body (`IDEMPOTENCY_KEY_REUSED`). Keys
expire; see the [API idempotency rules](https://kirora.io/docs/api/reference/idempotency).
Idempotency keys apply to individual calls; they can't be set on the client.

## Verify webhooks

Set `KIRORA_WEBHOOK_SECRET` to the webhook signing secret. Verify each webhook against
the **raw** request body, before decoding it: decoded and re-encoded JSON won't match the
signature.

In plain PHP:

```php
use Kirora\Webhook;

$webhook = Webhook::verify(
    getenv('KIRORA_WEBHOOK_SECRET'),
    file_get_contents('php://input'),
    getallheaders(), // or $_SERVER
);
if ($webhook === null) {
    http_response_code(401);
    exit;
}

// $webhook->payload is the decoded event; $webhook->headers->attempt counts deliveries.
// Deduplicate on $webhook->payload['eventId'], recording it as handled only once the work
// has succeeded.
http_response_code(200);
```

With a PSR-7 request, as in Slim or Symfony with the PSR-7 bridge, pass the request as
the headers:

```php
$webhook = Webhook::verify($secret, (string) $request->getBody(), $request);
```

`verify()` returns null for a webhook that fails verification, and rejects timestamps more
than 5 minutes in the past or future by default; pass `['tolerance' => 600]` to change
that. A missing secret or an invalid option throws an `InvalidArgumentException` instead.

### Verification diagnostics

To find out why a webhook failed, use `Webhook::assert()` instead. It throws a
`WebhookVerificationException` whose `reason` is a `WebhookVerificationFailure`:
`InvalidTimestamp`, `InvalidHeaders` (a missing signature, event or event id header, one
that doesn't match the body, or a bad attempt number), `SignatureMismatch` (usually the
wrong secret, or a body that was decoded first), `TimestampOutsideTolerance` (a replay, or
your server's clock is out of sync), or `BodyTooLarge` (over 10 MiB):

```php
use Kirora\Exceptions\WebhookVerificationException;

try {
    $webhook = Webhook::assert($secret, $rawBody, $headers);
} catch (WebhookVerificationException $error) {
    $logger->warning('Rejected a webhook', ['reason' => $error->reason->value]);
    http_response_code(401);
    exit;
}
```

`Webhook::verifySignature()` and `Webhook::readHeaders()` check the signature and read the
headers separately. The signature doesn't cover the event and event id headers, so once it
passes, trust the body's `eventType` and `eventId`, or use `verify()`, which checks they
match. The event types are the cases of `Kirora\Enums\WebhookEvent`. See the
[API webhook reference](https://kirora.io/docs/api/reference/webhooks) for the delivery
contract.

## Advanced usage

### Batch requests

`events->trackBatch()` sends up to 100 events at once. One event refused doesn't stop the
rest: the call succeeds, and each entry in `results`, in the order you sent them, has
either the event or an `error`:

```php
$batch = $kirora->events->trackBatch([
    'events' => array_map(fn (array $order) => [
        'idempotencyKey' => "order-{$order['id']}-completed", // stable, so a retry replays it
        'event' => ['externalId' => $order['userId'], 'name' => 'Order Completed'],
    ], $orders),
]);
foreach ($batch->results as $index => $result) {
    if (isset($result['error'])) {
        $logger->warning('Event not tracked', ['order' => $orders[$index]['id'], 'code' => $result['error']['code']]);
    }
}
```

An entry without its own `idempotencyKey` gets one made for it. An `ErrorException` from
`trackBatch()` means the whole batch was refused, such as for an invalid request.
`transactional->sendBatch()`, `contacts->identifyBatch()` and `contacts->deleteBatch()`
work the same way.

### Response details

Results carry the HTTP response they came from as `lastResponse()`:

```php
$contact = $kirora->contacts->get($contactId);
echo $contact->lastResponse()->requestId; // quote this to Kirora support
$contact->lastResponse()->rateLimit;      // limit, remaining, resetSeconds, from RateLimit-*

$sent = $kirora->transactional->send($message, ['idempotencyKey' => $key]);
if ($sent->lastResponse()->idempotencyReplay) {
    // An earlier request with this key already sent it; this is its stored response.
}
```

A page from `list()` has it too. It's null on a model nested inside another.

### Authenticate browser and mobile SDK users

The [web SDK](https://kirora.io/docs/sdks/web) and the mobile SDKs need a token from your
server to know who's signed in. Create one with the SDK token secret from
**Developer → SDK** in the dashboard, from a route only the signed-in user can call:

```php
use Kirora\UserToken;

echo UserToken::create(getenv('KIRORA_SDK_TOKEN_SECRET'), $currentUser->id);
```

Tokens last an hour by default. Pass a third argument to change that: a whole number of
seconds from 1 to 86,400 (24 hours). An empty secret or user id, or any other lifetime,
throws rather than signing a token.

### Partner workspaces

With a partner key, act in one of your tenants' workspaces by its `externalId`:

```php
$tenant = $kirora->forWorkspace('tenant-42');
$tenant->contacts->get($contactId);
```

You can also set `workspace` when creating the client, or per call. An empty workspace
throws an `InvalidArgumentException`, rather than acting in your own workspace.
`forWorkspace()` returns a new client; the one you called it on is unchanged.

Manage the workspaces themselves with `$kirora->workspaces`: `list()`, `all()`,
`create()`, `get()`, `update()`, `delete()` (after a grace period, which
`cancelDeletion()` can stop), `suspend()` and `resume()`.

```php
$kirora->workspaces->create(['name' => 'Acme', 'externalId' => 'tenant-42', 'country' => 'GB']);
```

To embed Kirora's segment or email builder in your app, create a session for the tenant on
your server and give its token to the browser. A session lasts 15 minutes.

```php
$session = $kirora->forWorkspace('tenant-42')->embedSessions->create(['components' => ['email_builder']]);
```

### Call endpoints without service methods

Endpoints without a service method can be called through the client, with the same
authentication, retries and errors. The path starts with `/v1`; encode each id in it with
`rawurlencode()`:

```php
// request() returns the whole decoded JSON body, envelope included
$body = $kirora->request('GET', '/v1/attributes?limit=10');

// requestData() unwraps `data`, and requestAll() pages like the services' all()
$attribute = $kirora->requestData('PUT', '/v1/attributes/' . rawurlencode('plan'), [
    'description' => 'The customer\'s billing plan',
]);
foreach ($kirora->requestAll('/v1/attributes') as $attribute) {
    echo $attribute['key'], "\n";
}
```

These return arrays rather than models. A 204, a `HEAD` or an empty body returns null from
`request()`, and any other body that isn't JSON throws an `UnserializableResponse`.

Each endpoint is retried as the API spec says it can be, and gets an idempotency key if it
takes one, just as a service method would. On an endpoint newer than your version of the
package, a `POST` or `PATCH` is retried after a network error or a 5xx only if you pass an
`idempotencyKey`, or `'repeatable' => true` to say it's safe to repeat.

For a body that isn't JSON or is too large to buffer, such as a CSV export,
`requestRaw()` returns the PSR-7 response without reading its body. It's retried and
throws as `request()` does, but `timeout` limits connecting and each read, not reading the
whole body:

```php
$response = $kirora->requestRaw('GET', '/v1/messages/exports/' . rawurlencode($id) . '/download');
$body = $response->getBody();
while (! $body->eof()) {
    fwrite(STDOUT, $body->read(8192));
}
```

## Testing your code

Pass a Guzzle client with a `MockHandler` to test your application code without making API
calls. Set `maxRetries` to `0` so failures return without retry delays. Successful API
responses wrap the result in `data`:

```php
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

$mock = new MockHandler([
    new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'data' => ['id' => 'list-123', 'name' => 'VIPs'],
    ])),
]);
$kirora = Kirora::client('test-key', [
    'maxRetries' => 0,
    'httpClient' => new Client(['handler' => HandlerStack::create($mock)]),
]);

echo $kirora->lists->get('list-123')->name; // VIPs
```

Add Guzzle's `Middleware::history()` to the stack to assert on the requests sent. Use
`baseUrl` if you prefer a local mock server. See [CONTRIBUTING.md](../CONTRIBUTING.md) for
the SDK's own development checks.
