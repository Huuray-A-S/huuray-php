<?php

declare(strict_types=1);

namespace Huuray\Internal;

/**
 * Collects the response headers cURL reports, as its `CURLOPT_HEADERFUNCTION`.
 *
 * cURL calls it once per header line: the status line, each header, and the
 * blank line that ends the block. It keeps the headers of the last response
 * only, so an interim `100 Continue`, or a proxy's answer to `CONNECT`, does not
 * mix its headers into the real response's.
 *
 * @internal
 */
final class ResponseHeaders
{
    /** @var array<string, string> */
    private array $headers = [];

    private ?string $last = null;

    /** Handles one header line and returns its length, as cURL requires; any other number aborts the transfer. */
    public function __invoke(\CurlHandle $handle, string $line): int
    {
        if (str_starts_with($line, 'HTTP/')) {
            // A new response starts: forget the previous one's headers.
            $this->headers = [];
            $this->last = null;
        } elseif (($line[0] ?? '') === ' ' || ($line[0] ?? '') === "\t") {
            // An obsolete folded line continues the previous header's value (RFC 9112, section 5.2).
            if ($this->last !== null && trim($line) !== '') {
                $this->headers[$this->last] .= ' ' . trim($line);
            }
        } elseif (($colon = strpos($line, ':')) !== false) {
            $name = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));
            // A repeated header's values are joined with ", " (RFC 9110, section 5.3).
            $this->headers[$name] = isset($this->headers[$name]) ? $this->headers[$name] . ', ' . $value : $value;
            $this->last = $name;
        }

        return strlen($line);
    }

    /**
     * @return array<string, string> Lower-cased header name => value, for the last response cURL saw. A name of
     *                               digits only, such as `1`, is an int key, as PHP stores such a key;
     *                               `RawResponse::header()` allows for that.
     */
    public function all(): array
    {
        return $this->headers;
    }
}
