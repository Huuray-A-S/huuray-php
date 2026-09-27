<?php

declare(strict_types=1);

namespace Huuray\Internal;

/**
 * Sleeps for any number of whole seconds, a day at a time.
 *
 * sleep() hands its seconds on as a 32-bit unsigned count — on Windows, a count of
 * milliseconds — so a large value wraps round to a short wait: past about 49 days
 * on Windows. A day at a time stays far below that everywhere.
 *
 * @internal
 */
final class Sleep
{
    /** The longest single sleep() call, in seconds: one day. */
    public const MAX_CHUNK_SECONDS = 86_400;

    /**
     * @param (\Closure(int): mixed)|null $sleep Sleeps the given seconds: sleep() itself, unless the test suite stands
     *                                           in for it.
     */
    public static function seconds(int $seconds, ?\Closure $sleep = null): void
    {
        $sleep ??= sleep(...);
        while ($seconds > 0) {
            $chunk = min($seconds, self::MAX_CHUNK_SECONDS);
            $sleep($chunk);
            $seconds -= $chunk;
        }
    }
}
