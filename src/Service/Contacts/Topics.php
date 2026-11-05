<?php

declare(strict_types=1);

namespace Kirora\Service\Contacts;

use Kirora\Models\ContactTopic;
use Kirora\Service\Service;

/**
 * A contact's choices on topics, for a preference centre.
 *
 * Reached as `$kirora->contacts->topics`. Each method takes `['idType' => 'externalId']` or
 * `['idType' => 'email']` in its query to look the contact up by your own id or its email.
 */
class Topics extends Service
{
    /**
     * Returns the contact's choice on every topic, all at once (listContactTopics).
     *
     * @param  array{idType?: 'id'|'externalId'|'email'}  $query
     * @param  array<string, mixed>  $options
     * @return list<ContactTopic>
     */
    public function list(string $contactId, array $query = [], array $options = []): array
    {
        return $this->many(ContactTopic::class, 'GET', self::path('/v1/contacts/%s/topics', $contactId), $query, $options);
    }

    /**
     * Joins or leaves a topic, on every channel or per channel; joining needs a consent
     * statement (setContactTopic). Safe to retry.
     *
     * @param  array<string, mixed>  $params
     * @param  array{idType?: 'id'|'externalId'|'email'}  $query
     * @param  array<string, mixed>  $options
     */
    public function set(string $contactId, string $topicId, array $params, array $query = [], array $options = []): ContactTopic
    {
        return $this->one(ContactTopic::class, 'PUT', self::path('/v1/contacts/%s/topics/%s', $contactId, $topicId), $query, $params, $options);
    }
}
