<?php

declare(strict_types=1);

namespace Huuray\Result;

/**
 * Result of a synchronous order. Vouchers are returned inline.
 *
 * `var_dump()` and `print_r()` redact the voucher codes; see {@see Voucher}.
 */
final readonly class CreateSyncOrderResult
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
