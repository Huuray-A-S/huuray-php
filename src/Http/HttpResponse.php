<?php

declare(strict_types=1);

namespace Huuray\Http;

use Huuray\Redact;

/** A completed response: the status, the entire body, already read, and the response headers. */
final readonly class HttpResponse
{
    /**
     * @param array<string, string> $headers Header name => value, a repeated header's values joined with ", ". Names
     *                                       may be in any case; the client looks them up case-insensitively.
     *                                       Optional, so a transport written before headers were read keeps
     *                                       working: the client then sees no `Retry-After`.
     */
    public function __construct(
        public int $status,
        public string $body,
        public array $headers = [],
    ) {}

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        // The body can carry voucher codes, so a dump shows only its size.
        return [
            'status' => $this->status,
            'headers' => Redact::redact($this->headers),
            'body' => sprintf('[%d bytes]', strlen($this->body)),
        ];
    }
}
