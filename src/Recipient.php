<?php

declare(strict_types=1);

namespace Huuray;

/**
 * One recipient — used both when ordering and when reading vouchers back.
 *
 *     new Recipient(name: 'Jane Doe', email: 'jane@example.com');
 *
 * `var_dump()` and `print_r()` mask `email` and `phone`; reading the properties,
 * or `json_encode()`, gives you the real values.
 */
final readonly class Recipient
{
    public function __construct(
        public ?string $name = null,
        /** Required when delivering by email. */
        public ?string $email = null,
        /** Required when delivering by SMS. */
        public ?string $phone = null,
        /** Your own identifier for this recipient. */
        public ?string $refId = null,
    ) {}

    /** @return array<string, string|null> */
    public function __debugInfo(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email === null || $this->email === '' ? $this->email : Redact::maskPartial($this->email),
            'phone' => $this->phone === null || $this->phone === '' ? $this->phone : Redact::maskPartial($this->phone),
            'refId' => $this->refId,
        ];
    }
}
