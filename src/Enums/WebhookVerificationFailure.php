<?php

declare(strict_types=1);

namespace Kirora\Enums;

/**
 * Why a webhook failed verification. See `WebhookVerificationException`.
 */
enum WebhookVerificationFailure: string
{
    /** The timestamp header is missing or isn't a whole number of seconds. */
    case InvalidTimestamp = 'invalid_timestamp';

    /**
     * No signature matches the body: the secret is wrong, or the body was changed, such as
     * parsed and re-encoded, before verification.
     */
    case SignatureMismatch = 'signature_mismatch';

    /** The timestamp is further from now than the tolerance: a replay, or a clock out of sync. */
    case TimestampOutsideTolerance = 'timestamp_outside_tolerance';

    /**
     * The signature, event or event id header is missing, the attempt header isn't a whole
     * number from 1, or the event or event id header doesn't match the signed body.
     */
    case InvalidHeaders = 'invalid_headers';

    /** The body is larger than 10 MiB. */
    case BodyTooLarge = 'body_too_large';
}
