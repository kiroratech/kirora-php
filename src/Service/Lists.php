<?php

declare(strict_types=1);

namespace Kirora\Service;

use Generator;
use Kirora\Models\AddedListContacts;
use Kirora\Models\DeletedListContacts;
use Kirora\Models\ListModel;
use Kirora\Page;

/**
 * Static lists of contacts, whose members you add and remove yourself.
 */
class Lists extends Service
{
    /**
     * Fetches a page of lists (listLists). See `all()` for every list.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $options
     * @return Page<ListModel>
     */
    public function list(array $query = [], array $options = []): Page
    {
        return $this->page(ListModel::class, '/v1/lists', $query, $options);
    }

    /**
     * Iterates over every list, fetching pages as it goes (listLists).
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $options
     * @return Generator<int, ListModel>
     */
    public function all(array $query = [], array $options = []): Generator
    {
        return $this->every(ListModel::class, '/v1/lists', $query, $options);
    }

    /**
     * Fetches a list (getList).
     *
     * @param  array<string, mixed>  $options
     */
    public function get(string $listId, array $options = []): ListModel
    {
        return $this->one(ListModel::class, 'GET', self::path('/v1/lists/%s', $listId), [], null, $options);
    }

    /**
     * Creates a list (createList). Retries reuse one idempotency key, so they never create two.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $options
     */
    public function create(array $params, array $options = []): ListModel
    {
        return $this->one(ListModel::class, 'POST', '/v1/lists', [], $params, $options);
    }

    /**
     * Changes a list (updateList). Safe to retry.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $options
     */
    public function update(string $listId, array $params, array $options = []): ListModel
    {
        return $this->one(ListModel::class, 'PATCH', self::path('/v1/lists/%s', $listId), [], $params, $options);
    }

    /**
     * Deletes a list, but not its contacts (deleteList). Safe to retry: a retry that finds it
     * already deleted succeeds.
     *
     * @param  array<string, mixed>  $options
     */
    public function delete(string $listId, array $options = []): void
    {
        $this->none('DELETE', self::path('/v1/lists/%s', $listId), [], $options);
    }

    /**
     * Adds contacts to a list, by `contactIds`, `externalIds` or `emails` (addListContacts).
     * Existing members are skipped. A key is made for the call, so a retry replays the first
     * result rather than reporting none added.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $options
     */
    public function addContacts(string $listId, array $params, array $options = []): AddedListContacts
    {
        return $this->one(AddedListContacts::class, 'POST', self::path('/v1/lists/%s/contacts', $listId), [], $params, $options);
    }

    /**
     * Removes contacts from a list, not from Kirora (deleteListContacts). Non-members are
     * skipped. A key is made for the call, so a retry replays the first result rather than
     * reporting none removed.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $options
     */
    public function deleteContacts(string $listId, array $params, array $options = []): DeletedListContacts
    {
        return $this->one(DeletedListContacts::class, 'POST', self::path('/v1/lists/%s/contacts/delete', $listId), [], $params, $options);
    }
}
