<?php

declare(strict_types=1);

namespace Huuray\Result;

use Huuray\Redact;

/**
 * An uploaded purchase order file.
 *
 * `var_dump()` and `print_r()` mask `fileName`, which can name a person;
 * reading the property, or `json_encode()`, gives you the real value.
 */
final readonly class UploadResult
{
    public function __construct(
        /**
         * Pass it as `purchaseOrderFileToken` on an order to attach the file to that
         * order's invoice. The token is consumed by the order it is used with.
         */
        public ?string $token,
        /** The file name the upload was stored with. */
        public ?string $fileName,
        /** The content type the file was recognized as. */
        public ?string $contentType,
        /** The size of the uploaded file in bytes. */
        public ?int $size,
    ) {}

    /** @return array<string, int|string|null> */
    public function __debugInfo(): array
    {
        return [
            'token' => $this->token,
            'fileName' => $this->fileName === null || $this->fileName === '' ? $this->fileName : Redact::maskPartial($this->fileName),
            'contentType' => $this->contentType,
            'size' => $this->size,
        ];
    }
}
