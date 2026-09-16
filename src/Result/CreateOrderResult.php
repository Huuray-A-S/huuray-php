<?php

declare(strict_types=1);

namespace Huuray\Result;

/** Result of an asynchronous order. No voucher data is returned. */
final readonly class CreateOrderResult
{
    public function __construct(
        /** Keep this: it identifies the order in `search()`, `resend()` and `cancel()`. */
        public ?string $orderUid,
        public ?string $refId,
    ) {}
}
