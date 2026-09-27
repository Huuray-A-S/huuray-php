<?php

declare(strict_types=1);

namespace Huuray\Result;

/**
 * The gift card PDFs of an order, or word that they are not ready yet.
 *
 * `var_dump()` and `print_r()` show each document's content as its size; see
 * {@see PdfDocument}.
 */
final readonly class PdfResult
{
    /**
     * @param list<PdfDocument> $documents
     */
    public function __construct(
        /**
         * True on HTTP 200, when `documents` holds the PDFs. False on HTTP 202: the
         * order is still in Huuray's queue, or a supplier has not delivered a code
         * yet, and `documents` is empty. Ask again after `retryAfter` seconds.
         */
        public bool $ready,
        public ?string $orderUid,
        /** One document per voucher, in the order's recipient order, or a single combined one. */
        public array $documents,
        /** Whole seconds to wait before asking again, from the `Retry-After` header; null when absent or unparseable. */
        public ?int $retryAfter,
    ) {}

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        // Each PdfDocument shows its content as its size when dumped.
        return [
            'ready' => $this->ready,
            'orderUid' => $this->orderUid,
            'documents' => $this->documents,
            'retryAfter' => $this->retryAfter,
        ];
    }
}
