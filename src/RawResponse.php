<?php

declare(strict_types=1);

namespace Huuray;

/**
 * A decoded response body plus the HTTP status, which some endpoints use
 * semantically — `206 Partial Content` on Cancel and Resend.
 *
 * @internal Not part of the semver-stable surface; use {@see HuurayClient::request()}.
 */
final readonly class RawResponse
{
    public function __construct(
        public mixed $data,
        public int $httpStatus,
    ) {}

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['data' => Redact::redact($this->data), 'httpStatus' => $this->httpStatus];
    }
}
