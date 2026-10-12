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

    /**
     * Sleeps for any number of milliseconds: the wait before a retry.
     *
     * usleep() keeps only the low 32 bits of its microseconds, so a wait of about 71
     * minutes or more wraps round to a short one. Whole seconds go through
     * {@see self::seconds()}, a day at a time, and usleep() gets only the rest.
     *
     * @param (\Closure(int): mixed)|null $sleep  Sleeps the given seconds, as for seconds().
     * @param (\Closure(int): mixed)|null $usleep Sleeps the given microseconds, always under a second: usleep()
     *                                            itself, unless the test suite stands in for it.
     */
    public static function milliseconds(int $milliseconds, ?\Closure $sleep = null, ?\Closure $usleep = null): void
    {
        self::seconds(intdiv($milliseconds, 1000), $sleep);

        $rest = $milliseconds % 1000;
        if ($rest > 0) {
            ($usleep ?? usleep(...))($rest * 1000);
        }
    }
}
