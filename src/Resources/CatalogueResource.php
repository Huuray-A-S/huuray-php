<?php

declare(strict_types=1);

namespace Huuray\Resources;

use Huuray\Exception\HuurayException;
use Huuray\Internal\Wire;
use Huuray\Result\CatalogueProduct;
use Huuray\Result\ListCatalogueResult;

class CatalogueResource extends AbstractResource
{
    /**
     * Lists available products.
     *
     * `POST /v4/Catalogue`
     *
     * A read, despite being a POST — it takes a request body but changes nothing.
     *
     * @param bool $all false (default) — only products your account can order, including your
     *                  discount and each `productToken`. true — the entire Huuray catalogue,
     *                  without tokens or discounts.
     *
     * @throws \InvalidArgumentException before any request, for a custom nonce that is empty, over 50 characters or outside visible ASCII
     * @throws HuurayException
     */
    public function list(bool $all = false): ListCatalogueResult
    {
        $data = $this->client->send('POST', '/v4/Catalogue', Wire::object(['All' => $all]), retryable: true)->data;

        $products = [];
        foreach (Wire::rows($data, 'Products') as $row) {
            $products[] = new CatalogueProduct(
                productToken: Wire::string($row, 'ProductToken'),
                brandName: Wire::string($row, 'BrandName'),
                country: Wire::string($row, 'Country'),
                countryCode: Wire::string($row, 'CountryCode'),
                discount: Wire::number($row, 'Discount'),
                denominations: Wire::string($row, 'Denominations'),
                currency: Wire::string($row, 'Currency'),
                realTimeStock: Wire::string($row, 'RealTimeStock'),
                categories: Wire::string($row, 'Categories'),
                languageCode: Wire::string($row, 'LanguageCode'),
                active: Wire::bool($row, 'Active') ?? false,
                brandDescription: Wire::string($row, 'BrandDescription'),
                redemptionInstructions: Wire::string($row, 'RedemptionInstructions'),
                logoFile: Wire::string($row, 'LogoFile'),
            );
        }

        return new ListCatalogueResult($products);
    }
}
