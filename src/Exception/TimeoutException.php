<?php

declare(strict_types=1);

namespace Huuray\Exception;

/** The request exceeded the configured `timeoutMs`. */
class TimeoutException extends ConnectionException
{
    public function __construct(
        string $method,
        string $path,
        public readonly int $timeoutMs,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('%s %s timed out after %dms.', $method, $path, $timeoutMs),
            $method,
            $path,
            $previous,
        );
    }
}
