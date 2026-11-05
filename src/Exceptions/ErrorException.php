<?php

declare(strict_types=1);

namespace Kirora\Exceptions;

use Exception;
use Kirora\Enums\ErrorCode;
use Kirora\RateLimit;
use Kirora\ResponseInfo;
use Kirora\Retry;
use Throwable;

/**
 * An error response from the API. Branch on `errorCode`, or use `is()`.
 *
 * `getCode()` is the HTTP status, as `status` is.
 */
class ErrorException extends Exception implements KiroraException
{
    /**
     * @param  string  $errorCode  Always set: when the response has none, it comes from the
     *   status. A code added to the API after this version of the SDK comes through as is.
     * @param  string  $errorMessage  What went wrong, or "HTTP <status>" when the response
     *   says nothing.
     * @param  list<array<string, mixed>>  $errors  Each field the request got wrong, as
     *   `['field' => ..., 'message' => ...]`, with a message to show beside it. Set on
     *   `VALIDATION_ERROR` and `DRAFT_INCOMPLETE`.
     * @param  mixed  $details  Any further detail the API gave, as decoded JSON.
     * @param  ?string  $requestId  Identifies the request to Kirora support.
     * @param  ?float  $retryAfter  How many seconds the API asked you to wait before retrying.
     * @param  ?RateLimit  $rateLimit  Null when the response has no valid `RateLimit-*` headers.
     * @param  ?ResponseInfo  $response  The response the error came from.
     * @param  ?Throwable  $previous  Why its body couldn't be read, when it couldn't.
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly string $errorMessage,
        public readonly int $status,
        public readonly array $errors = [],
        public readonly mixed $details = null,
        public readonly ?string $requestId = null,
        public readonly ?float $retryAfter = null,
        public readonly ?RateLimit $rateLimit = null,
        public readonly ?ResponseInfo $response = null,
        ?Throwable $previous = null,
    ) {
        $message = "Kirora: $errorCode (HTTP $status): $errorMessage";
        if ($requestId !== null && $requestId !== '') {
            $message .= " [request $requestId]";
        }
        parent::__construct($message, $status, $previous);
    }

    /**
     * Whether the error has the given code.
     */
    public function is(ErrorCode|string $code): bool
    {
        return $this->errorCode === ($code instanceof ErrorCode ? $code->value : $code);
    }

    /**
     * Whether this version of the SDK knows the error's code.
     */
    public function isKnownCode(): bool
    {
        return ErrorCode::isKnown($this->errorCode);
    }

    /**
     * Whether the request may succeed if sent again: after a rate limit, a server error, or
     * `IDEMPOTENCY_KEY_IN_USE`, while an earlier request with the same key is still running.
     * It doesn't say whether repeating the request is safe from duplicates.
     */
    public function isRetryable(): bool
    {
        return $this->status === 429
            || $this->status >= 500
            || $this->errorCode === ErrorCode::IdempotencyKeyInUse->value;
    }

    /**
     * Builds the error from a response. A body that isn't the API's JSON, as from a proxy,
     * still makes an error, from the status alone.
     *
     * @internal
     */
    public static function fromResponse(ResponseInfo $response, ?string $body, ?Throwable $previous = null): self
    {
        $decoded = $body === null ? null : json_decode($body, true);
        $error = is_array($decoded) && is_array($decoded['error'] ?? null) ? $decoded['error'] : [];

        $code = is_string($error['code'] ?? null) && $error['code'] !== ''
            ? $error['code']
            : self::codeForStatus($response->status);
        $message = is_string($error['message'] ?? null) && $error['message'] !== ''
            ? $error['message']
            : "HTTP {$response->status}";
        $requestId = $response->requestId
            ?? (is_string($error['requestId'] ?? null) && $error['requestId'] !== '' ? $error['requestId'] : null);

        // Each entry is checked on its own, so a malformed one is dropped without losing the rest.
        $errors = [];
        if (is_array($error['errors'] ?? null) && array_is_list($error['errors'])) {
            foreach ($error['errors'] as $entry) {
                if (is_array($entry) && is_string($entry['field'] ?? null) && is_string($entry['message'] ?? null)) {
                    $errors[] = $entry;
                }
            }
        }

        return new self(
            errorCode: $code,
            errorMessage: $message,
            status: $response->status,
            errors: $errors,
            details: $error['details'] ?? null,
            requestId: $requestId,
            retryAfter: Retry::parseRetryAfter($response->header('Retry-After'), time()),
            rateLimit: $response->rateLimit,
            response: $response,
            previous: $previous,
        );
    }

    private static function codeForStatus(int $status): string
    {
        return (match ($status) {
            401 => ErrorCode::Unauthorized,
            403 => ErrorCode::Forbidden,
            404 => ErrorCode::NotFound,
            405 => ErrorCode::MethodNotAllowed,
            409 => ErrorCode::Conflict,
            413 => ErrorCode::PayloadTooLarge,
            415 => ErrorCode::UnsupportedMediaType,
            429 => ErrorCode::RateLimited,
            503 => ErrorCode::ServiceUnavailable,
            default => $status < 500 ? ErrorCode::BadRequest : ErrorCode::InternalError,
        })->value;
    }
}
