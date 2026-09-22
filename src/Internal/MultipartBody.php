<?php

declare(strict_types=1);

namespace Huuray\Internal;

/**
 * A multipart/form-data request body, built in full before it is sent.
 *
 * The client hands it to the transport as an ordinary string body, with
 * {@see self::$contentType} — which carries the boundary — as the request's
 * Content-Type. So a {@see \Huuray\Http\Transport} sends it exactly as it sends
 * JSON, and the transport contract does not change.
 *
 * The bytes are private: `var_dump()`, `print_r()` and {@see \Huuray\Redact}
 * show only their size.
 *
 * @internal
 */
final class MultipartBody
{
    private function __construct(
        /** `multipart/form-data; boundary=...`, the request's Content-Type. */
        public readonly string $contentType,
        private readonly string $body,
    ) {}

    /**
     * A body of one file part.
     *
     * The part name and file name are escaped the way the HTML standard's
     * multipart/form-data encoding escapes them: a line feed, a carriage return and
     * a double quote become `%0A`, `%0D` and `%22`, so no value can end the quoted
     * parameter or the part's headers. Nothing else is changed. `$contentType` is
     * written as given, so the caller must refuse a line break or NUL in it.
     */
    public static function withFile(
        string $name,
        #[\SensitiveParameter]
        string $fileName,
        string $contentType,
        #[\SensitiveParameter]
        string $bytes,
    ): self {
        // The boundary must not occur in anything it delimits, or the part would end early.
        do {
            $boundary = 'huuray-' . bin2hex(random_bytes(16));
        } while (
            str_contains($bytes, $boundary)
            || str_contains($fileName, $boundary)
            || str_contains($contentType, $boundary)
            || str_contains($name, $boundary)
        );

        $headers = 'Content-Disposition: form-data; name="' . self::escape($name) . '"; filename="' . self::escape($fileName) . '"'
            . "\r\nContent-Type: " . $contentType . "\r\n";

        return new self(
            'multipart/form-data; boundary=' . $boundary,
            '--' . $boundary . "\r\n" . $headers . "\r\n" . $bytes . "\r\n--" . $boundary . "--\r\n",
        );
    }

    /** The body exactly as it is sent. */
    public function body(): string
    {
        return $this->body;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['contentType' => $this->contentType, 'body' => sprintf('[%d bytes]', strlen($this->body))];
    }

    private static function escape(
        #[\SensitiveParameter]
        string $value,
    ): string {
        return str_replace(["\n", "\r", '"'], ['%0A', '%0D', '%22'], $value);
    }
}
