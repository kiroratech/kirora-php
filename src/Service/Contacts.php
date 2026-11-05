<?php

declare(strict_types=1);

namespace Kirora\Service;

use Generator;
use Kirora\Contracts\Transporter;
use Kirora\Models\ContactDataExport;
use Kirora\Models\ContactDetail;
use Kirora\Models\DeleteContactsResult;
use Kirora\Models\IdentifyContactsBatchResult;
use Kirora\Page;
use Kirora\Service\Contacts\Installations;
use Kirora\Service\Contacts\Lists;
use Kirora\Service\Contacts\Segments;
use Kirora\Service\Contacts\Topics;

/**
 * The people you send to.
 *
 * Each method that takes a contact id also takes `['idType' => 'externalId']` or
 * `['idType' => 'email']` as its query, to look the contact up by your own id or its email
 * address instead of Kirora's id. That query comes before the call options, so pass `[]` to
 * skip it.
 *
 * A contact's lists, segments, topics and push installations are reached through its
 * properties, as `$kirora->contacts->segments->list($contactId)`.
 */
class Contacts extends Service
{
    public readonly Lists $lists;

    public readonly Segments $segments;

    public readonly Topics $topics;

    public readonly Installations $installations;

    /**
     * @internal
     */
    public function __construct(Transporter $transporter)
    {
        parent::__construct($transporter);
        $this->lists = new Lists($transporter);
        $this->segments = new Segments($transporter);
        $this->topics = new Topics($transporter);
        $this->installations = new Installations($transporter);
    }

    /**
     * Fetches a page of contacts (listContacts). Its first page also has the total
     * `meta['count']`. See `all()` for every contact.
     *
     * @param  array<string, mixed>  $query  Such as `query`, `limit` and `cursor`.
     * @param  array<string, mixed>  $options
     * @return Page<ContactDetail>
     */
    public function list(array $query = [], array $options = []): Page
    {
        return $this->page(ContactDetail::class, '/v1/contacts', $query, $options);
    }

    /**
     * Iterates over every contact, fetching pages as it goes (listContacts). Stop whenever
     * you've had enough; no further pages are fetched.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $options
     * @return Generator<int, ContactDetail>
     */
    public function all(array $query = [], array $options = []): Generator
    {
        return $this->every(ContactDetail::class, '/v1/contacts', $query, $options);
    }

    /**
     * Fetches a contact (getContact).
     *
     * @param  array{idType?: 'id'|'externalId'|'email'}  $query
     * @param  array<string, mixed>  $options
     */
    public function get(string $contactId, array $query = [], array $options = []): ContactDetail
    {
        return $this->one(ContactDetail::class, 'GET', self::path('/v1/contacts/%s', $contactId), $query, null, $options);
    }

    /**
     * Changes a contact's fields (updateContact). Leave a field out to keep its value, or set
     * it to null to clear it. Safe to retry, since applying the same change twice is a no-op,
     * unless it changes the email or externalId the contact is looked up by: a retry after it
     * succeeded would look for the old one, so it isn't retried.
     *
     * @param  array<string, mixed>  $params
     * @param  array{idType?: 'id'|'externalId'|'email'}  $query
     * @param  array<string, mixed>  $options
     */
    public function update(string $contactId, array $params, array $query = [], array $options = []): ContactDetail
    {
        return $this->one(ContactDetail::class, 'PATCH', self::path('/v1/contacts/%s', $contactId), $query, $params, $options);
    }

    /**
     * Deletes a contact for good (deleteContact). Safe to retry: a retry that finds it
     * already deleted succeeds.
     *
     * @param  array{idType?: 'id'|'externalId'|'email'}  $query
     * @param  array<string, mixed>  $options
     */
    public function delete(string $contactId, array $query = [], array $options = []): void
    {
        $this->none('DELETE', self::path('/v1/contacts/%s', $contactId), $query, $options);
    }

    /**
     * Creates or updates a contact, matched by externalId, then email, then phone, at least
     * one of which is required (identifyContact). A key is made for the call, so a retry
     * replays the first result, including whether it created the contact.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $options
     */
    public function identify(array $params, array $options = []): ContactDetail
    {
        return $this->one(ContactDetail::class, 'POST', '/v1/contacts/identify', [], $params, $options);
    }

    /**
     * Identifies up to 100 contacts, each as `identify()` does (identifyContactsBatch). Each
     * entry in `results`, in the order sent, has either the contact or an error.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $options
     */
    public function identifyBatch(array $params, array $options = []): IdentifyContactsBatchResult
    {
        return $this->one(IdentifyContactsBatchResult::class, 'POST', '/v1/contacts/batch', [], $params, $options);
    }

    /**
     * Deletes up to 100 contacts, each by id, externalId or email (deleteContacts). A key is
     * made for the call, so a retry replays the first result rather than reporting the
     * contacts it deleted as not found.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $options
     */
    public function deleteBatch(array $params, array $options = []): DeleteContactsResult
    {
        return $this->one(DeleteContactsResult::class, 'POST', '/v1/contacts/delete', [], $params, $options);
    }

    /**
     * Merges a contact into `$params['keep']` and deletes it (mergeContacts). A key is made
     * for the call, so a retry after it succeeded replays the result rather than failing on
     * the contact that's gone.
     *
     * @param  array<string, mixed>  $params
     * @param  array{idType?: 'id'|'externalId'|'email'}  $query
     * @param  array<string, mixed>  $options
     */
    public function merge(string $contactId, array $params, array $query = [], array $options = []): ContactDetail
    {
        return $this->one(ContactDetail::class, 'POST', self::path('/v1/contacts/%s/merge', $contactId), $query, $params, $options);
    }

    /**
     * Returns everything stored about a contact, for a data request (getContactExport).
     *
     * @param  array{idType?: 'id'|'externalId'|'email'}  $query
     * @param  array<string, mixed>  $options
     */
    public function export(string $contactId, array $query = [], array $options = []): ContactDataExport
    {
        return $this->one(ContactDataExport::class, 'GET', self::path('/v1/contacts/%s/export', $contactId), $query, null, $options);
    }
}
