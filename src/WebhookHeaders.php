<?php

declare(strict_types=1);

namespace Kirora;

/**
 * The headers Kirora sends with each webhook, as `Webhook::readHeaders()` returns them.
 */
final class WebhookHeaders
{
    /**
     * @param  string  $event  The event type, such as `message.delivered`; see `Enums\WebhookEvent`.
     * @param  string  $eventId  The same on every delivery attempt of an event, so deduplicate on it.
     * @param  int  $attempt  Which delivery attempt this is, from 1.
     * @param  string  $timestamp  Seconds since the epoch, as signed.
     */
    public function __construct(
        public readonly string $event,
        public readonly string $eventId,
        public readonly int $attempt,
        public readonly string $timestamp,
        public readonly string $signature,
    ) {
    }
}
