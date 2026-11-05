<?php

declare(strict_types=1);

namespace Kirora\Service\Contacts;

use Generator;
use Kirora\Models\ContactListMembership;
use Kirora\Page;
use Kirora\Service\Service;

/**
 * The lists a contact belongs to.
 *
 * Reached as `$kirora->contacts->lists`. Each method takes `['idType' => 'externalId']` or
 * `['idType' => 'email']` in its query to look the contact up by your own id or its email.
 */
class Lists extends Service
{
    /**
     * Fetches a page of the lists a contact belongs to (listContactLists).
     *
     * @param  array<string, mixed>  $query  Such as `idType`, `limit` and `cursor`.
     * @param  array<string, mixed>  $options
     * @return Page<ContactListMembership>
     */
    public function list(string $contactId, array $query = [], array $options = []): Page
    {
        return $this->page(ContactListMembership::class, self::path('/v1/contacts/%s/lists', $contactId), $query, $options);
    }

    /**
     * Iterates over every list a contact belongs to, fetching pages as it goes (listContactLists).
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $options
     * @return Generator<int, ContactListMembership>
     */
    public function all(string $contactId, array $query = [], array $options = []): Generator
    {
        return $this->every(ContactListMembership::class, self::path('/v1/contacts/%s/lists', $contactId), $query, $options);
    }
}
