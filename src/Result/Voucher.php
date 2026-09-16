<?php

declare(strict_types=1);

namespace Huuray\Result;

use Huuray\Recipient;
use Huuray\Redact;

/**
 * One gift card.
 *
 * `code`, `cvv` and `redeemLink` are **bearer instruments**: whoever holds them
 * holds the value. `var_dump()` and `print_r()` — the usual way a value ends up in
 * a log — show them as `[redacted: bearer value]`. Reading the properties, or
 * `json_encode()`, gives you the real values: that is you deliberately reading
 * your own data.
 */
final readonly class Voucher
{
    public function __construct(
        /** Voucher identifier, used by `resend()` and `cancel()`. */
        public ?int $id,
        /**
         * The redeemable code.
         *
         * **Blank unless `ReturnCode` is enabled on your B2B account.** If you need
         * codes returned to your system rather than delivered by Huuray, ask your
         * Huuray contact to enable it.
         */
        public ?string $code,
        public ?string $cvv,
        public ?string $redeemLink,
        public ?string $expires,
        public ?Recipient $recipient,
    ) {}

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'id' => $this->id,
            'code' => self::hide($this->code),
            'cvv' => self::hide($this->cvv),
            'redeemLink' => self::hide($this->redeemLink),
            'expires' => $this->expires,
            // Recipient masks its own email and phone.
            'recipient' => $this->recipient,
        ];
    }

    private static function hide(?string $value): ?string
    {
        return $value === null || $value === '' ? $value : Redact::BEARER_MARKER;
    }
}
