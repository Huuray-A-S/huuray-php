<?php

declare(strict_types=1);

namespace Huuray\Result;

/**
 * One gift card PDF: one voucher's, or several vouchers' in a combined document.
 *
 * `content` is a **bearer instrument**. The PDF shows the redeemable code and,
 * depending on the template, the CVV and QR codes, so whoever holds the file holds
 * the value. Send it only to its recipient, keep it only as long as you need it,
 * and never log it. `var_dump()` and `print_r()` show it as its size, and `Redact`
 * as `[redacted: bearer value]`; reading the property gives you the bytes.
 */
final readonly class PdfDocument
{
    /**
     * @param list<int> $voucherIds
     */
    public function __construct(
        /** The vouchers in the document: one, or every selected voucher for a combined document. */
        public array $voucherIds,
        /** The PDF template the document was built from; null for a combined document built from several. */
        public ?string $pdfTemplateUid,
        /** A suggested file name, e.g. `giftcard-5123401.pdf`. */
        public ?string $fileName,
        /** The document's media type, `application/pdf`. */
        public ?string $contentType,
        /** The PDF's bytes, decoded from the base64 the API sends. Null when the API sent none. */
        public ?string $content,
    ) {}

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'voucherIds' => $this->voucherIds,
            'pdfTemplateUid' => $this->pdfTemplateUid,
            'fileName' => $this->fileName,
            'contentType' => $this->contentType,
            // The bytes are the gift card itself, so a dump shows only their size.
            'content' => $this->content === null ? null : sprintf('[%d bytes]', strlen($this->content)),
        ];
    }
}
