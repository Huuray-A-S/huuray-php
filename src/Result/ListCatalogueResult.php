<?php

declare(strict_types=1);

namespace Huuray\Result;

final readonly class ListCatalogueResult
{
    /**
     * @param list<CatalogueProduct> $products
     */
    public function __construct(
        public array $products,
    ) {}
}
