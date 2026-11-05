<?php

declare(strict_types=1);

namespace Kirora\Service;

use Generator;
use Kirora\Models\OutboundMessage;
use Kirora\Page;

/**
 * The log of every message sent, by any campaign, journey or transactional send.
 */
class Messages extends Service
{
    /**
     * Fetches a page of messages, newest first (listMessages). Filter by `status`,
     * `recipient`, `correlationId` and more. See `all()` for every message.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $options
     * @return Page<OutboundMessage>
     */
    public function list(array $query = [], array $options = []): Page
    {
        return $this->page(OutboundMessage::class, '/v1/messages', $query, $options);
    }

    /**
     * Iterates over every message, newest first, fetching pages as it goes (listMessages).
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $options
     * @return Generator<int, OutboundMessage>
     */
    public function all(array $query = [], array $options = []): Generator
    {
        return $this->every(OutboundMessage::class, '/v1/messages', $query, $options);
    }

    /**
     * Fetches one message from the log, whatever sent it (getMessage).
     *
     * @param  array<string, mixed>  $options
     */
    public function get(string $messageId, array $options = []): OutboundMessage
    {
        return $this->one(OutboundMessage::class, 'GET', self::path('/v1/messages/%s', $messageId), [], null, $options);
    }
}
