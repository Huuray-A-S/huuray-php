<?php

declare(strict_types=1);

namespace Huuray\Result;

final readonly class ListBalancesResult
{
    /**
     * @param list<Balance> $balances
     */
    public function __construct(
        public array $balances,
    ) {}
}
