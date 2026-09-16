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
 *   retryable    Balance, ExchangeRates, Catalogue, Template, Stock, Search
 *   never        Order, Resend, Cancel
 *
 * Four of the retryable operations are POSTs. They are POSTs because they take a
 * request body, not because they change anything.
 *
 * @internal
 */
final class RetryPolicy
{
    /**
     * HTTP statuses worth repeating a *read* for.
     *
     * `429` is included defensively: it is not a documented response on any v4
     * endpoint, so this client never assumes rate limiting exists — but if one
     * appears, backing off is strictly better than hammering.
     */
    private const RETRYABLE_STATUS = [408, 425, 429, 500, 502, 503, 504];

    private function __construct() {}

    /** Whether a status should be retried, given the operation is already known to be safe to repeat. */
    public static function isRetryableStatus(int $status): bool
    {
        return in_array($status, self::RETRYABLE_STATUS, true);
    }

    /** Exponential backoff with full jitter, so parallel clients do not resonate. */
    public static function backoffDelayMs(int $attempt, RetryOptions $options): int
    {
        $exponential = (int) min(
            (float) $options->baseDelayMs * (2 ** min(max($attempt, 0), 30)),
            (float) $options->maxDelayMs,
        );

        // random_int, not a CSPRNG requirement: this is jitter to desynchronise
        // clients, and random_int is simply the cleanest uniform integer source.
        return $exponential <= 0 ? 0 : random_int(0, $exponential);
    }
}
