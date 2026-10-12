<?php

declare(strict_types=1);

namespace Huuray\Exception;

/**
 * 429: the rate limit for your IP address was exceeded, and the request was not processed.
 *
 * Because it was not processed, it is safe to send the same request again after
 * waiting `retryAfter` seconds. That holds for an order, a resend, a cancel and an
 * upload too, so a 429 is never an {@see IndeterminateOrderException}.
 *
 * A read is retried for you first: the client waits `retryAfter` seconds, at least
 * 1, in place of its own backoff. It throws this once those retries are used up,
 * or at once when `retryAfter` is longer than `RetryOptions::$maxDelayMs`. Orders,
 * resends, cancels and uploads are never retried automatically, so there it is
 * thrown at the first 429.
 */
class RateLimitException extends ApiException
{
    public function __construct(
        string $message,
        int $httpStatus,
        ?int $status,
        ?string $statusMessage,
        mixed $body,
        string $method,
        string $path,
        /**
         * The `Retry-After` header: how many whole seconds to wait before retrying.
         * Null when the response carried none, or one that is not whole seconds.
         */
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, $httpStatus, $status, $statusMessage, $body, $method, $path);
    }
}
