<?php

declare(strict_types=1);

namespace Kirora\Service\Contacts;

use Generator;
use Kirora\Models\PushInstallation;
use Kirora\Page;
use Kirora\Service\Service;

/**
 * A contact's devices and browsers registered for push.
 *
 * Reached as `$kirora->contacts->installations`. Each method takes `['idType' => 'externalId']` or
 * `['idType' => 'email']` in its query to look the contact up by your own id or its email.
 */
class Installations extends Service
{
    /**
     * Fetches a page of the contact's devices and browsers registered for push (listPushInstallations).
     *
     * @param  array<string, mixed>  $query  Such as `idType`, `limit` and `cursor`.
     * @param  array<string, mixed>  $options
     * @return Page<PushInstallation>
     */
    public function list(string $contactId, array $query = [], array $options = []): Page
    {
        return $this->page(PushInstallation::class, self::path('/v1/contacts/%s/installations', $contactId), $query, $options);
    }

    /**
     * Iterates over every device and browser the contact has registered for push, fetching pages as it goes (listPushInstallations).
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $options
     * @return Generator<int, PushInstallation>
     */
    public function all(string $contactId, array $query = [], array $options = []): Generator
    {
        return $this->every(PushInstallation::class, self::path('/v1/contacts/%s/installations', $contactId), $query, $options);
    }

    /**
     * Creates or updates a device or browser by its `externalInstallationId`
     * (registerPushInstallation). A key is made for the call, so a retry replays the first
     * result.
     *
     * @param  array<string, mixed>  $params
     * @param  array{idType?: 'id'|'externalId'|'email'}  $query
     * @param  array<string, mixed>  $options
     */
    public function register(string $contactId, array $params, array $query = [], array $options = []): PushInstallation
    {
        return $this->one(PushInstallation::class, 'POST', self::path('/v1/contacts/%s/installations', $contactId), $query, $params, $options);
    }

    /**
     * Removes a device or browser (deletePushInstallation). Safe to retry: a retry that finds
     * it already removed succeeds.
     *
     * @param  array{idType?: 'id'|'externalId'|'email'}  $query
     * @param  array<string, mixed>  $options
     */
    public function delete(string $contactId, string $externalInstallationId, array $query = [], array $options = []): void
    {
        $this->none('DELETE', self::path('/v1/contacts/%s/installations/%s', $contactId, $externalInstallationId), $query, $options);
    }
}
