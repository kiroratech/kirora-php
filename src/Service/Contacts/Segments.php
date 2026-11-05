<?php

declare(strict_types=1);

namespace Kirora\Service\Contacts;

use Generator;
use Kirora\Models\ContactSegment;
use Kirora\Page;
use Kirora\Service\Service;

/**
 * The segments a contact is in.
 *
 * Reached as `$kirora->contacts->segments`. Each method takes `['idType' => 'externalId']` or
 * `['idType' => 'email']` in its query to look the contact up by your own id or its email.
 */
class Segments extends Service
{
    /**
     * Fetches a page of the segments a contact is in (listContactSegments).
     *
     * @param  array<string, mixed>  $query  Such as `idType`, `query`, `limit` and `cursor`.
     * @param  array<string, mixed>  $options
     * @return Page<ContactSegment>
     */
    public function list(string $contactId, array $query = [], array $options = []): Page
    {
        return $this->page(ContactSegment::class, self::path('/v1/contacts/%s/segments', $contactId), $query, $options);
    }

    /**
     * Iterates over every segment a contact is in, fetching pages as it goes
     * (listContactSegments).
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $options
     * @return Generator<int, ContactSegment>
     */
    public function all(string $contactId, array $query = [], array $options = []): Generator
    {
        return $this->every(ContactSegment::class, self::path('/v1/contacts/%s/segments', $contactId), $query, $options);
    }
}
