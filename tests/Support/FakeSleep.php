<?php

declare(strict_types=1);

namespace Huuray\Tests\Support;

/**
 * Stands in for the client's wait before a retry, so a test can see how long the
 * client meant to wait without waiting at all. Pass it as `sleep: $sleep(...)`.
 */
final class FakeSleep
{
    /** @var list<int> Every wait, in milliseconds, in order. */
    public array $waits = [];

    public function __invoke(int $milliseconds): void
    {
        $this->waits[] = $milliseconds;
    }
}
