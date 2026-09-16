<?php

declare(strict_types=1);

namespace Huuray\Result;

final readonly class CheckStockResult
{
    public function __construct(
        /** Number of gift cards available, or null if the API did not report one. */
        public ?int $stock,
    ) {}
}
