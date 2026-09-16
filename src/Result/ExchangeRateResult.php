<?php

declare(strict_types=1);

namespace Huuray\Result;

final readonly class ExchangeRateResult
{
    public function __construct(
        /** The exchange rate, exactly as the API returned it. */
        public int|float|null $exchangeRate,
        /** Spread in percent. */
        public int|float|null $spread,
    ) {}
}
