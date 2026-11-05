<?php

declare(strict_types=1);

namespace Kirora;

/**
 * A webhook that passed `Webhook::assert()`.
 */
final class VerifiedWebhook
{
    /**
     * @param  WebhookHeaders  $headers  The request's headers, whose `event` and `eventId`
     *                                   match the body's.
     * @param  array<string, mixed>  $payload  The decoded body: the event, with `eventId`,
     *                                         `eventType` and the event's own fields.
     */
    public function __construct(
        public readonly WebhookHeaders $headers,
        public readonly array $payload,
    ) {
    }
}
