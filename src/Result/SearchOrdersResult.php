<?php

declare(strict_types=1);

namespace Huuray\Result;

/**
 * Vouchers from a previous order.
 *
 * `var_dump()` and `print_r()` redact the voucher codes; see {@see Voucher}.
 */
final readonly class SearchOrdersResult
{
    /**
     * @param list<Voucher> $vouchers
     */
    public function __construct(
        public ?string $orderUid,
        public ?string $refId,
        public array $vouchers,
    ) {}

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        // Each Voucher redacts itself when dumped.
        return ['orderUid' => $this->orderUid, 'refId' => $this->refId, 'vouchers' => $this->vouchers];
    }
}
