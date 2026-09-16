<?php

declare(strict_types=1);

namespace Huuray\Result;

/** One currency balance on your B2B account. */
final readonly class Balance
{
    public function __construct(
        /** ISO alpha-3 currency code. */
        public ?string $currency,
        /** Available balance **in minor units** — `50000` is 500.00. */
        public int $balance,
        /** Whether this is a master currency on the account. */
        public bool $master,
    ) {}
}
