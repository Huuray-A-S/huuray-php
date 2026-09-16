<?php

declare(strict_types=1);

namespace Huuray\Result;

/** A product in the Huuray catalogue. */
final readonly class CatalogueProduct
{
    public function __construct(
        /**
         * Unique product identifier, used when ordering.
         *
         * Only present when `all` is false. Requesting the entire catalogue omits
         * tokens, because they describe your account's access, not the public list.
         */
        public ?string $productToken,
        public ?string $brandName,
        public ?string $country,
        /** ISO alpha-2 country code. */
        public ?string $countryCode,
        /** Your discount on this product, in percent. Only present when `all` is false. */
        public int|float|null $discount,
        /** Available denominations, comma-separated, as returned by the API. */
        public ?string $denominations,
        /** ISO alpha-3 currency code. */
        public ?string $currency,
        /** Either real-time generated or drawn from stock. */
        public ?string $realTimeStock,
        /** Categories, comma-separated, as returned by the API. */
        public ?string $categories,
        /** ISO alpha-2 language code. */
        public ?string $languageCode,
        public bool $active,
        public ?string $brandDescription,
        public ?string $redemptionInstructions,
        public ?string $logoFile,
    ) {}
}
