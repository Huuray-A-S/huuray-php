<?php

declare(strict_types=1);

namespace Huuray\Result;

final readonly class ResendResult
{
    public function __construct(
        public ?int $numberOfResends,
        /**
         * True when the API answered `206 Partial Content` — some resends
         * succeeded and some did not. Treating this as plain success is a common bug.
         */
        public bool $partial,
    ) {}
}
