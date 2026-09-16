<?php

declare(strict_types=1);

namespace Huuray\Result;

final readonly class CancelledVoucher
{
    public function __construct(
        public int $id,
        public bool $cancelled,
    ) {}
}
