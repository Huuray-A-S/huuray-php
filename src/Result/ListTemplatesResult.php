<?php

declare(strict_types=1);

namespace Huuray\Result;

final readonly class ListTemplatesResult
{
    /**
     * @param list<Template>    $templates    Email and SMS delivery templates.
     * @param list<PdfTemplate> $pdfTemplates PDF templates, used to deliver codes as a document attached to an email.
     */
    public function __construct(
        public array $templates,
        public array $pdfTemplates,
    ) {}
}
