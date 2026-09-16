<?php

declare(strict_types=1);

namespace Huuray\Result;

/** A delivery template — the email or SMS your recipients receive. */
final readonly class Template
{
    public function __construct(
        /** Pass this as `templateId` when ordering. */
        public int $id,
        public ?string $name,
        /** Template type, e.g. email or SMS, as named by the API. */
        public ?string $type,
        /** ISO alpha-2 language code. */
        public ?string $language,
        public ?string $sender,
        public ?string $subject,
        /** Template body including HTML. */
        public ?string $formattedText,
        /** Template body as plain text. */
        public ?string $plainText,
    ) {}
}
