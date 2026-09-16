<?php

declare(strict_types=1);

namespace Huuray\Result;

/** A PDF template — a document attached to the emails a delivery template sends. */
final readonly class PdfTemplate
{
    public function __construct(
        /** Pass this as `pdfTemplateUid` when ordering, alongside an email `templateId`. */
        public ?string $uid,
        public ?string $name,
        /** PDF template type, as named by the API. */
        public ?string $type,
        /** ISO alpha-2 language code. */
        public ?string $language,
        /** The country the template can be used for; null means any country. */
        public ?string $country,
        /** The brand the template can be used for; null means any brand. */
        public ?string $brandName,
    ) {}
}
