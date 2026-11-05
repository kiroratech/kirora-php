<?php

declare(strict_types=1);

namespace Kirora\Service;

use Kirora\Models\TrackedEvent;
use Kirora\Models\TrackEventsBatchResult;

/**
 * What your contacts do, which starts journeys and feeds segments.
 */
class Events extends Service
{
    /**
     * Records an event (trackEvent). Retries reuse one idempotency key, so they never record
     * it twice. Pass your own `idempotencyKey`, derived from the event in your application, to
     * make your own retries safe too.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $options
     */
    public function track(array $params, array $options = []): TrackedEvent
    {
        return $this->one(TrackedEvent::class, 'POST', '/v1/events/track', [], $params, $options);
    }

    /**
     * Records up to 100 events, each with its own `idempotencyKey` (trackEventsBatch). One
     * event refused doesn't stop the rest: each entry in `results`, in the order sent, has
     * either the event or an error. An exception means the whole batch was refused.
     *
     * An entry without its own key gets one per call, which covers the SDK's retries only.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $options
     */
    public function trackBatch(array $params, array $options = []): TrackEventsBatchResult
    {
        return $this->one(TrackEventsBatchResult::class, 'POST', '/v1/events/batch', [], $params, $options);
    }
}
