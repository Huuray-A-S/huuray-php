<?php

declare(strict_types=1);

namespace Huuray\Result;

final readonly class CancelResult
{
    /**
     * @param list<CancelledVoucher> $vouchers
     * @param bool                   $partial  True when the API answered `206 Partial Content` —
     *                                         inspect `vouchers` to see which ones were not cancelled.
     */
    public function __construct(
        public ?string $orderUid,
        public bool $orderCancelled,
        public array $vouchers,
        public bool $partial,
    ) {}
}
