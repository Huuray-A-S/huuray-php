<?php

declare(strict_types=1);

namespace Huuray\Internal;

/**
 * Collects the response body cURL reports, as its `CURLOPT_WRITEFUNCTION`.
 *
 * With `CURLOPT_RETURNTRANSFER` instead, the reused cURL handle keeps the last
 * body until the next request: for a gift card PDF, megabytes of a bearer
 * instrument that outlive the caller's result. take() hands the body over and
 * empties the collector, so once the transport returns, nothing but the
 * response holds it.
 *
 * @internal
 */
final class ResponseBody
{
    private string $body = '';

    /** Appends one chunk and returns its length, as cURL requires; any other number aborts the transfer. */
    public function __invoke(\CurlHandle $handle, string $chunk): int
    {
        $this->body .= $chunk;

        return strlen($chunk);
    }

    /** The body collected so far. The collector keeps nothing of it. */
    public function take(): string
    {
        $body = $this->body;
        $this->body = '';

        return $body;
    }
}
