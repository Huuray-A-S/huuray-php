<?php

declare(strict_types=1);

namespace Huuray\Internal;

use Huuray\RetryOptions;

/**
 * Retry policy.
 *
 * The v4 API exposes no idempotency key. `RefID` is a reference you choose for
 * your own reconciliation; it is not a server-side deduplication key. So a
 * retried `POST /v4/Order` can create a second order, and a retried
 * `POST /v4/Resend` can re-deliver a live gift card.
 *
 * Because of that, retries are **opt-in per operation**, never inferred from the
 * HTTP method. Each resource method declares whether it is safe to repeat:
 *
 *   retryable    Balance, ExchangeRates, Catalogue, Template, Stock, Search, Pdf
 *   never        Order, Resend, Cancel, Upload
 *
 * Upload moves no value, but a retried upload stores the file a second time,
 * and each stored upload holds one of the account's pending upload slots.
 *
 * Five of the retryable operations are POSTs. They are POSTs because they take a
 * request body, not because they change anything.
 *
 * A 429 does not change that split. The API did not process the request, so
 * sending it again is safe, but for the four operations that are never retried
 * the caller decides when: the client only throws RateLimitException.
 *
 * @internal
 */
final class RetryPolicy
{
    /**
     * HTTP statuses worth repeating a *read* for.
     *
     * `429` is a documented response on every v4 operation: the rate limit for the
     * caller's IP address was exceeded and the request was not processed. Its
     * `Retry-After` header says how long to wait; see {@see self::retryAfterDelayMs()}.
     */
    private const RETRYABLE_STATUS = [408, 425, 429, 500, 502, 503, 504];

    private function __construct() {}

    /** Whether a status should be retried, given the operation is already known to be safe to repeat. */
    public static function isRetryableStatus(int $status): bool
    {
        return in_array($status, self::RETRYABLE_STATUS, true);
    }

    /**
     * The wait before repeating a read that was answered 429 with a `Retry-After`, in
     * milliseconds: the seconds the API named, at least one, in place of the computed
     * backoff. Null when that is longer than `maxDelayMs`: the client then throws at
     * once rather than sleeps, so the caller decides.
     */
    public static function retryAfterDelayMs(int $retryAfter, RetryOptions $options): ?int
    {
        $seconds = max(1, $retryAfter);

        // Compared in whole seconds, before multiplying: whatever the header names,
        // the milliseconds returned are at most maxDelayMs and cannot overflow an int.
        return $seconds > intdiv($options->maxDelayMs, 1000) ? null : $seconds * 1000;
    }

    /** Exponential backoff with full jitter, so parallel clients do not resonate. */
    public static function backoffDelayMs(int $attempt, RetryOptions $options): int
    {
        // In integers, and with the ceiling tested before the doubling, so neither can
        // overflow however large baseDelayMs and maxDelayMs are.
        $factor = 1 << min(max($attempt, 0), 30);
        $exponential = $options->baseDelayMs > intdiv($options->maxDelayMs, $factor)
            ? $options->maxDelayMs
            : $options->baseDelayMs * $factor;

        // random_int, not a CSPRNG requirement: this is jitter to desynchronise
        // clients, and random_int is simply the cleanest uniform integer source.
        return $exponential <= 0 ? 0 : random_int(0, $exponential);
    }
}
