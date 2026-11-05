<?php

declare(strict_types=1);

use Kirora\Exceptions\UnserializableResponse;
use Kirora\Models\ContactDetail;
use Kirora\Page;

it('fetches one page', function () {
    $api = fakeApi([page([['id' => 'c1'], ['id' => 'c2']], 'next', ['count' => 2])]);
    $page = $api->client->contacts->list(['query' => 'ada', 'limit' => 2]);

    expect($page)->toBeInstanceOf(Page::class)
        ->and($page)->toHaveCount(2)
        ->and($page->data[0])->toBeInstanceOf(ContactDetail::class)
        ->and($page->data[0]->id)->toBe('c1')
        ->and($page->meta['count'])->toBe(2)
        ->and($page->nextCursor())->toBe('next')
        ->and($page->hasMore())->toBeTrue()
        ->and($page->lastResponse()?->status)->toBe(200)
        ->and(array_map(fn ($c) => $c->id, iterator_to_array($page)))->toBe(['c1', 'c2'])
        ->and($api->request()->getUri()->getQuery())->toBe('query=ada&limit=2');
});

it('iterates over every item, following the cursor with the same filters', function () {
    $api = fakeApi([
        page([['id' => 'c1'], ['id' => 'c2']], 'p2'),
        page([], 'p3'),
        page([['id' => 'c3']]),
    ]);
    $ids = [];
    foreach ($api->client->contacts->all(['query' => 'ada']) as $contact) {
        $ids[] = $contact->id;
    }

    expect($ids)->toBe(['c1', 'c2', 'c3'])
        ->and($api->count())->toBe(3)
        ->and($api->request(1)->getUri()->getQuery())->toBe('query=ada&cursor=p2')
        ->and($api->request(2)->getUri()->getQuery())->toBe('query=ada&cursor=p3');
});

it('fetches nothing more once you stop', function () {
    $api = fakeApi([page([['id' => 'c1'], ['id' => 'c2']], 'p2')]);
    foreach ($api->client->contacts->all() as $contact) {
        break;
    }
    expect($api->count())->toBe(1);
});

it('fetches nothing until you iterate', function () {
    $api = fakeApi([]);
    $api->client->contacts->all();
    expect($api->count())->toBe(0);
});

it('stops when a cursor comes round again', function () {
    $api = fakeApi([page([['id' => 'a']], 'A'), page([['id' => 'b']], 'B'), page([['id' => 'c']], 'A')]);
    iterator_to_array($api->client->campaigns->all());
})->throws(UnserializableResponse::class, 'with cursor A again, so the list would never end');

it('refuses a body that isn\'t a page', function (array $body) {
    fakeApi([json(200, $body)])->client->lists->list();
})->with([
    [['data' => []]],
    [['data' => [], 'meta' => []]],
    [['data' => [], 'meta' => ['pagination' => ['limit' => 1]]]],
    [['data' => [], 'meta' => ['pagination' => ['nextCursor' => null]]]],
    [['data' => ['a' => 1], 'meta' => ['pagination' => ['limit' => 1, 'nextCursor' => null]]]],
])->throws(UnserializableResponse::class, 'without a page of `data` and `meta.pagination`');

it('pages endpoints without a method as arrays', function () {
    $api = fakeApi([page([['id' => 's1']], 'n'), page([['id' => 's2']])]);
    expect(iterator_to_array($api->client->requestAll('/v1/segments'), false))->toBe([['id' => 's1'], ['id' => 's2']]);
});

it('fetches one page of an endpoint without a method', function () {
    $api = fakeApi([page([['id' => 's1']])]);
    $page = $api->client->requestPage('/v1/segments', ['limit' => 1]);
    expect($page->data)->toBe([['id' => 's1']])->and($page->nextCursor())->toBeNull();
});

it('counts the cursor you start from as followed', function () {
    $api = fakeApi([page([['id' => 'a']], 'B'), page([['id' => 'b']], 'A')]);
    $ids = [];
    try {
        foreach ($api->client->contacts->all(['cursor' => 'A']) as $contact) {
            $ids[] = $contact->id;
        }
        $this->fail('expected the loop to be caught');
    } catch (UnserializableResponse) {
        expect($ids)->toBe(['a', 'b'])->and($api->count())->toBe(2);
    }
});

it('refuses a page with an item that is not an object', function (mixed $item) {
    $api = fakeApi([json(200, [
        'data' => [['id' => 'c1'], $item],
        'meta' => ['pagination' => ['limit' => 20, 'nextCursor' => null]],
    ], ['X-Request-Id' => 'req_p'])]);
    expect(fn () => $api->client->contacts->list())
        ->toThrow(fn (UnserializableResponse $error) => expect($error->getMessage())->toContain('with an item in its page that is not an object')
            ->and($error->status)->toBe(200)
            ->and($error->requestId)->toBe('req_p'));
})->with([
    'a string' => ['unexpected'],
    'a number' => [42],
    'null' => [null],
    'an array' => [[1, 2]],
]);

it('refuses a non-object item while iterating, on a later page', function () {
    $api = fakeApi([page([['id' => 'c1']], 'n'), page(['unexpected'])]);
    $seen = [];
    expect(function () use ($api, &$seen) {
        foreach ($api->client->contacts->all() as $contact) {
            $seen[] = $contact->id;
        }
    })->toThrow(UnserializableResponse::class, 'not an object');
    expect($seen)->toBe(['c1']);
});

it('refuses a non-object item on an endpoint without a method too', function () {
    fakeApi([page([7])])->client->requestPage('/v1/attributes');
})->throws(UnserializableResponse::class, 'not an object');

it('accepts an empty object as an item', function () {
    $page = fakeApi([page([new stdClass()])])->client->contacts->list();
    expect($page->data)->toHaveCount(1);
});
