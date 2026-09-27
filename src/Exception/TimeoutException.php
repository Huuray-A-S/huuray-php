<?php

declare(strict_types=1);

namespace Huuray\Exception;

/**
 * The request exceeded the configured `timeoutMs`, or `pdfs->getWhenReady()` gave
 * up waiting for a gift card PDF within its `maxWaitMs`, which `timeoutMs` then holds.
 */
class TimeoutException extends ConnectionException
{
    /**
     * @param string      $detail Appended to the message, e.g. what a timed-out request may have left behind.
     * @param string|null $lead   Replaces the message's opening sentence, "METHOD PATH timed out after Nms.", for a
     *                            wait that gave up rather than a request that timed out.
     */
    public function __construct(
        string $method,
        string $path,
        public readonly int $timeoutMs,
        ?\Throwable $previous = null,
        string $detail = '',
        ?string $lead = null,
    ) {
        parent::__construct(
            ($lead ?? sprintf('%s %s timed out after %dms.', $method, $path, $timeoutMs)) . ($detail === '' ? '' : ' ' . $detail),
            $method,
            $path,
            $previous,
        );
    }
}
