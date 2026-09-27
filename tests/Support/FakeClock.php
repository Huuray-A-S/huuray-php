<?php

declare(strict_types=1);

namespace Huuray\Tests\Support;

/**
 * Stands in for the clock and the sleep of `pdfs->getWhenReady()`, so a test can
 * poll for minutes of API time in no time at all. A wait is recorded, and moves
 * the clock on by exactly that long.
 */
final class FakeClock
{
    /** @var list<int> Every wait, in seconds, in order. */
    public array $waits = [];

    /** Monotonic seconds. Not zero, so a deadline computed as "zero plus something" cannot pass by accident. */
    public float $now = 1_000.0;

    public function sleep(int $seconds): void
    {
        $this->waits[] = $seconds;
        $this->now += $seconds;
    }

    public function now(): float
    {
        return $this->now;
    }
}
