<?php

declare(strict_types=1);

namespace Huuray;

/**
 * Retry knobs for read operations. Defaults are deliberately conservative.
 *
 * Retries only ever apply to operations that are safe to repeat: Balance,
 * Catalogue, Template, Stock, ExchangeRates and Search. Order, Resend and Cancel
 * are never retried, whatever is configured here — the API has no idempotency key.
 *
 * Any value left unset (or passed as null) falls back to its default; it never
 * disables retrying. A negative value is clamped to zero.
 */
final readonly class RetryOptions
{
    public const DEFAULT_MAX_RETRIES = 2;
    public const DEFAULT_BASE_DELAY_MS = 250;
    public const DEFAULT_MAX_DELAY_MS = 4000;

    /** Attempts after the first. `0` disables retrying entirely. */
    public int $maxRetries;

    /** Base delay in milliseconds; doubles per attempt, with full jitter. */
    public int $baseDelayMs;

    /** Ceiling for a single backoff wait, in milliseconds. */
    public int $maxDelayMs;

    public function __construct(
        ?int $maxRetries = null,
        ?int $baseDelayMs = null,
        ?int $maxDelayMs = null,
    ) {
        $this->maxRetries = max(0, $maxRetries ?? self::DEFAULT_MAX_RETRIES);
        $this->baseDelayMs = max(0, $baseDelayMs ?? self::DEFAULT_BASE_DELAY_MS);
        $this->maxDelayMs = max(0, $maxDelayMs ?? self::DEFAULT_MAX_DELAY_MS);
    }
}
