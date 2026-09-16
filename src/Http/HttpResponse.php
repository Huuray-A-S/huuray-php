<?php

declare(strict_types=1);

namespace Huuray\Http;

/** A completed response: the status and the entire body, already read. */
final readonly class HttpResponse
{
    public function __construct(
        public int $status,
        public string $body,
    ) {}

    /** @return array<string, int|string> */
    public function __debugInfo(): array
    {
        // The body can carry voucher codes, so a dump shows only its size.
        return ['status' => $this->status, 'body' => sprintf('[%d bytes]', strlen($this->body))];
    }
}
