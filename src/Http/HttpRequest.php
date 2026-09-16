<?php

declare(strict_types=1);

namespace Huuray\Http;

use Huuray\Redact;

/** One fully built, signed request, handed to a {@see Transport}. */
final readonly class HttpRequest
{
    /**
     * @param string                $method    Upper-case HTTP verb.
     * @param string                $url       Absolute URL, including any query string.
     * @param array<string, string> $headers   Header name => value. Includes the auth headers.
     * @param string|null           $body      The JSON body, or null for **no body at all**.
     * @param int                   $timeoutMs Total time allowed for the exchange. Always at least 1.
     */
    public function __construct(
        public string $method,
        public string $url,
        public array $headers,
        public ?string $body,
        public int $timeoutMs,
    ) {}

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'method' => $this->method,
            'url' => $this->url,
            'headers' => Redact::redact($this->headers),
            'body' => $this->body === null ? null : sprintf('[%d bytes]', strlen($this->body)),
            'timeoutMs' => $this->timeoutMs,
        ];
    }
}
