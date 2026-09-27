<?php

declare(strict_types=1);

namespace Huuray;

/**
 * A decoded response body plus the HTTP status, which some endpoints use
 * semantically — `206 Partial Content` on Cancel and Resend — and the response
 * headers.
 *
 * @internal Not part of the semver-stable surface; use {@see HuurayClient::request()}.
 */
final readonly class RawResponse
{
    /**
     * @param array<array-key, mixed> $headers The response headers, as the transport returned them: name => value.
     *                                         Not trusted to match that: a name of digits only, such as `1`, is an
     *                                         int key in a PHP array, and a custom transport may pass anything.
     */
    public function __construct(
        public mixed $data,
        public int $httpStatus,
        public array $headers = [],
    ) {}

    /**
     * The value of a response header, looked up case-insensitively; null when the response did not carry it, or
     * carried something other than a string, such as a PSR-7 style list of values.
     */
    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                return is_string($value) ? $value : null;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['data' => Redact::redact($this->data), 'httpStatus' => $this->httpStatus, 'headers' => Redact::redact($this->headers)];
    }
}
