<?php

declare(strict_types=1);

namespace Kirora\Service;

use Kirora\Models\OutboundMessage;
use Kirora\Models\SendTransactionalMessagesBatchResult;
use Kirora\Models\TransactionalSendResult;

/**
 * One-off messages to one recipient, such as receipts and password resets.
 */
class Transactional extends Service
{
    /**
     * Sends a transactional message (sendTransactionalMessage). Retries reuse one idempotency
     * key, so they never send twice.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $options
     */
    public function send(array $params, array $options = []): TransactionalSendResult
    {
        return $this->one(TransactionalSendResult::class, 'POST', '/v1/transactional/messages', [], $params, $options);
    }

    /**
     * Sends up to 100 messages, each with its own `idempotencyKey`
     * (sendTransactionalMessagesBatch). Each entry in `results`, in the order sent, has either
     * the sent messages or an error. An entry without its own key gets one per call.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $options
     */
    public function sendBatch(array $params, array $options = []): SendTransactionalMessagesBatchResult
    {
        return $this->one(SendTransactionalMessagesBatchResult::class, 'POST', '/v1/transactional/messages/batch', [], $params, $options);
    }

    /**
     * Cancels a message scheduled with `sendAt`, before it's sent
     * (cancelScheduledTransactionalMessage). A key is made for the call, so a retry replays
     * the result rather than failing once it's cancelled.
     *
     * @param  array<string, mixed>  $options
     */
    public function cancel(string $messageId, array $options = []): OutboundMessage
    {
        return $this->one(OutboundMessage::class, 'POST', self::path('/v1/transactional/messages/%s/cancel', $messageId), [], null, $options);
    }
}
