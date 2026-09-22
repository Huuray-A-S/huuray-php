<?php

declare(strict_types=1);

namespace Huuray\Exception;

/** The request exceeded the configured `timeoutMs`. */
class TimeoutException extends ConnectionException
{
    /**
     * @param string $detail Appended to the message, e.g. what a timed-out request may have left behind.
     */
    public function __construct(
        string $method,
        string $path,
        public readonly int $timeoutMs,
        ?\Throwable $previous = null,
        string $detail = '',
    ) {
        parent::__construct(
            sprintf('%s %s timed out after %dms.', $method, $path, $timeoutMs) . ($detail === '' ? '' : ' ' . $detail),
            $method,
            $path,
            $previous,
        );
    }
}
